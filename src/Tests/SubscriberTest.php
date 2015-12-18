<?php

namespace MakinaCorpus\Drupal\APubSub\Tests;

use APubSub\Backend\Drupal7\D7Backend;
use APubSub\Tests\AbstractSubscriberTest;

class SubscriberTest extends AbstractSubscriberTest
{
    /**
     * @var \DatabaseConnection
     */
    protected $dbConnection;

    protected function cleanup()
    {
        // Test could have been skipped
        if (null !== $this->dbConnection) {
            foreach (array('apb_queue', 'apb_msg', 'apb_msg_chan', 'apb_sub', 'apb_chan', 'apb_sub_map') as $table) {
                $this->dbConnection->query("DELETE FROM {" . $table . "}");
            }
        }
    }

    protected function setUp()
    {
        if (!$this->dbConnection = DrupalHelper::findDrupalDatabaseConnection()) {
            $this->markTestSkipped("Drupal 7 connection handler and database information are not available.");
        } else {
            // In case a parent test run failed, the tables where not cleaned
            // up properly
            $this->cleanup();

            parent::setUp();
        }
    }

    protected function tearDown()
    {
        $this->cleanup();

        parent::tearDown();
    }

    protected function setUpBackend()
    {
        return new D7Backend($this->dbConnection);
    }
}
