<?php

namespace MakinaCorpus\Drupal\APubSub\Backend;

use MakinaCorpus\APubSub\Backend\DefaultChannel;
use MakinaCorpus\APubSub\CursorInterface;
use MakinaCorpus\APubSub\Field;
use MakinaCorpus\APubSub\Misc;

/**
 * Message cursor is a bit tricky: the query will be provided by the caller
 * and may change depending on the source (subscriber or subscription)
 */
class DrupalChannelCursor extends AbstractDrupalCursor
{
    /**
     * Should JOIN with subscriptions
     *
     * @var boolean
     */
    protected $queryOnSub = false;

    /**
     * Should JOIN with subscriptions and queue
     *
     * @var boolean
     */
    protected $queryOnQueue = false;

    /**
     * {@inheritdoc}
     */
    public function getAvailableSorts()
    {
        return array(
            Field::CHAN_ID,
            Field::CHAN_CREATED_TS,
            Field::CHAN_UPDATED_TS,
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function applyConditions(array $conditions)
    {
        $ret = array();

        foreach ($conditions as $field => $value) {
            switch ($field) {

                case Field::CHAN_ID:
                    $ret['c.name'] = $value;
                    break;

                case Field::CHAN_CREATED_TS:
                    if ($value instanceof \DateTime) {
                        $value = $value->format(Misc::SQL_DATETIME);
                    }
                    $ret['c.created'] = $value;
                    break;

                case Field::CHAN_UPDATED_TS:
                    if ($value instanceof \DateTime) {
                        $value = $value->format(Misc::SQL_DATETIME);
                    }
                    $ret['c.updated'] = $value;
                    break;

                case Field::MSG_UNREAD:
                    $ret['q.unread'] = (int)(bool)$value;
                    $this->queryOnQueue = true;
                    break;

                case Field::MSG_ORIGIN:
                    $sq = $this
                        ->backend
                        ->getConnection()
                        ->select('apb_msg', 'm')
                        ->condition('m.origin', $value)
                        ->where('q.msg_id = m.id')
                    ;
                    $sq->addExpression('1');
                    $ret['c.id'] = ['exists' => $sq];
                    $this->queryOnQueue = true;
                    break;

                // WARNING: NOT PROUD OF THIS ONE!
                case Field::MSG_TYPE:
                    $ret['q.type_id'] = $this
                        ->backend
                        ->getTypeRegistry()
                        ->convertQueryCondition($value)
                    ;
                    $this->queryOnQueue = true;
                    break;

                case Field::SUB_ID:
                    $ret['s.id'] = $value;
                    $this->queryOnSub = true;
                    break;

                case Field::SUBER_NAME:
                    $ret['s.name'] = $value;
                    $this->queryOnSub = true;
                    break;

                default:
                    trigger_error(sprintf("% does not support filter %d yet",
                        get_class($this), $field));
                    break;
            }
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     */
    protected function applySorts(\SelectQueryInterface $query, array $sorts)
    {
        if (empty($sorts)) {
            $query->orderBy('c.id', 'ASC');
        } else {
            foreach ($sorts as $sort => $order) {

                if ($order === CursorInterface::SORT_DESC) {
                    $direction = 'DESC';
                } else {
                    $direction = 'ASC';
                }

                switch ($sort)
                {
                    case Field::CHAN_ID:
                        $query->orderBy('c.name', $direction);
                        break;

                    case Field::CHAN_CREATED_TS:
                        $query->orderBy('c.created', $direction);
                        break;

                    case Field::CHAN_UPDATED_TS:
                        $query->orderBy('c.updated', $direction);
                        break;

                    default:
                        throw new \InvalidArgumentException("Unsupported sort field");
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function createObjectInstance(\stdClass $record)
    {
        return new DefaultChannel(
            $record->name,
            $this->backend,
            \DateTime::createFromFormat(Misc::SQL_DATETIME, $record->created),
            \DateTime::createFromFormat(Misc::SQL_DATETIME, $record->updated),
            empty($record->title) ? null : $record->title,
            (int)$record->id
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function buildQuery()
    {
        $cx = $this->backend->getConnection();

        $query = $cx->select('apb_chan', 'c');

        if ($this->queryOnSub || $this->queryOnQueue) {
            $query->join('apb_sub', 's', "s.chan_id = c.id");
        }
        if ($this->queryOnQueue) {
            $query->join('apb_queue', 'q', "q.sub_id = s.id");
        }

        $query->fields('c');

        return $query;
    }

    /**
     * Create temporary table from current query
     *
     * @return string New temporary table name, filled in with query primary
     *                identifiers only
     */
    private function createTempTable()
    {
        $query = clone $this->getQuery();
        $query->distinct(false);

        // I am sorry but I have to be punished for I am writing this
        $selectFields = &$query->getFields();
        foreach ($selectFields as $key => $value) {
            unset($selectFields[$key]);
        }
        // Again.
        $tables = &$query->getTables();
        foreach ($tables as $key => $table) {
           unset($tables[$key]['all_fields']);
        }

        $query->fields('c', array('id'));

        // Create a temp table containing identifiers to update: this is
        // mandatory because you cannot use the apb_queue in the UPDATE
        // query subselect
        $cx = $this->backend->getConnection();
        $tempTableName = $cx->queryTemporary((string)$query, $query->getArguments());
        // @todo temporary deactivating this, PostgresSQL does not like it (I
        // have no idea why exactly) - I have to get rid of those temp tables
        // anyway.
        // $cx->schema()->addIndex($tempTableName, $tempTableName . '_idx', array('id'));

        return $tempTableName;
    }

    /**
     * {@inheritdoc}
     */
    public function delete()
    {
        $cx = $this->backend->getConnection();
        $tx = null;

        try {
            $tx = $cx->startTransaction();

            // Deleting messages in queue implicates doing it using the queue id:
            // because the 'apb_queue' table is our primary FROM table (in most
            // cases) we need to proceed using a temporary table
            $tempTableName = $this->createTempTable();

            $cx->query("
                DELETE
                FROM {apb_chan}
                WHERE
                    id IN (
                        SELECT id
                        FROM {" . $tempTableName ."}
                    )
            ");

            // There will be leftovers into message table. This will also force
            // a queue cleanup while removing data in those.
            $cx->query("
                DELETE
                FROM {apb_msg}
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM {apb_msg_chan}
                    WHERE msg_id = id
                )
            ");

            $cx->query("DROP TABLE {" . $tempTableName . "}");

            unset($tx); // Explicit commit

        } catch (\Exception $e) {
            if ($tx) {
                try {
                    $tx->rollback();
                } catch (\Exception $e2) {}
            }

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(array $values)
    {
        if (empty($values)) {
            return;
        }

        $queryValues = array();

        // First build values and ensure the users don't do anything stupid
        foreach ($values as $key => $value) {
            switch ($key) {

                case Field::CHAN_TITLE:
                    $queryValues['title'] = $value;
                    break;

                default:
                    throw new \RuntimeException(sprintf(
                        "%s field is unsupported for update",
                        $key));
            }
        }

        // Updating messages in queue implicates doing it using the queue id:
        // because the 'apb_queue' table is our primary FROM table (in most
        // cases) we need to proceed using a temporary table
        $tempTableName = $this->createTempTable();

        $cx = $this->backend->getConnection();

        $select = $cx
            ->select($tempTableName, 't')
            ->fields('t', array('id'))
        ;

        $cx
            ->update('apb_chan')
            ->fields($queryValues)
            ->condition('id', $select, 'IN')
            ->execute()
        ;

        $cx->query("DROP TABLE {" . $tempTableName . "}");
    }
}
