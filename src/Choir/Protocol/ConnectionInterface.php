<?php

declare(strict_types=1);

namespace Choir\Protocol;

interface ConnectionInterface
{
    /**
     * 关闭连接
     *
     * @param mixed $data 传入关闭连接的数据
     */
    public function close(mixed $data = null);
}
