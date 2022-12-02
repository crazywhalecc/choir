<?php

namespace Choir\Http\Client;

interface TimeoutInterface
{
    /**
     * 设置 Client 的超时时间
     *
     * @param int $timeout 超时时间（毫秒）
     */
    public function setTimeout(int $timeout);
}
