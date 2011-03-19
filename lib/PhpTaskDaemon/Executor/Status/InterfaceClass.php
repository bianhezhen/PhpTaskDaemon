<?php

/**
 * @package PhpTaskDaemon
 * @subpackage Status
 * @copyright Copyright (C) 2010 Dirk Engels Websolutions. All rights reserved.
 * @author Dirk Engels <d.engels@dirkengels.com>
 * @license https://github.com/DirkEngels/PhpTaskDaemon/blob/master/doc/LICENSE
 */
namespace PhpTaskDaemon\Executor\Status;

interface InterfaceClass {

	public function get();
    public function set($status);
}