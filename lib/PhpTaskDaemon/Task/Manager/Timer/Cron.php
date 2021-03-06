<?php
/**
 * @package PhpTaskDaemon
 * @subpackage Task\Manager\Timer
 * @copyright Copyright (C) 2011 Dirk Engels Websolutions. All rights reserved.
 * @author Dirk Engels <d.engels@dirkengels.com>
 * @license https://github.com/DirkEngels/PhpTaskDaemon/blob/master/doc/LICENSE
 */

namespace PhpTaskDaemon\Task\Manager\Timer;

require_once \LIBRARY_PATH . '/cron.phar';

class Cron extends Interval {

    /**
     * Defines the cron wait (absolute time) to wait for.
     * 
     * @see PhpTaskDaemon\Task\Manager\Timer.Interval::getTimeToWait()
     * @return integer The number of seconds to wait before running again.
     */
    public function getTimeToWait() {
        $cron = Cron\CronExpression::factory( '@daily' );
        $cron->isDue();
        $nextRun = $cron->getNextRunDate();
        $now = new DateTime();

        if ( $nextRun <= $now ) {
            return 0;
        }
        return $nextRun->getTimestamp() - $now->getTimestamp();
    }

}