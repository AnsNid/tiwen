<?php

namespace App\user\Listener;

use xphp\Event\Contract\ListenerInterface;

class UserRegisteredListener implements ListenerInterface {

  public function listen(): array {
    return [];
  }

  /**
   * 事件处理
   * @param object|string $event
   */
  public function process(object|string $event): void {
  }
}
