<?php declare(strict_types=1);

namespace Workbunny\WebmanSharedCache\Traits;

use Closure;
use Workbunny\WebmanSharedCache\Cache;
use Workbunny\WebmanSharedCache\Future;
use Error;

/**
 * @method static array GetChannel(string $key) Channel 获取
 * @method static bool ChPublish(string $key, mixed $message, bool $store = true, null|string|int $workerId = null) Channel 发布消息
 * @method static bool|int ChCreateListener(string $key, string|int $workerId, Closure $listener) Channel 监听器创建
 * @method static void ChRemoveListener(string $key, string|int $workerId, bool $remove = false) Channel 监听器移除
 */
trait ChannelMethods
{
    use BasicMethods;

    /** @var string 通道前缀 */
    protected static string $_CHANNEL = '#Channel#';

    /**
     * @var array = [channelKey => listeners]
     */
    protected static array $_listeners = [];

    /**
     * @param string $key
     * @return string
     */
    public static function GetChannelKey(string $key): string
    {
        return self::$_CHANNEL . $key;
    }

    /**
     * 通道获取
     *
     * @param string $key
     * @return array = [
     *   workerId = [
     *      'futureId' => futureId,
     *      'value'    => array
     *   ]
     * ]
     */
    protected static function _GetChannel(string $key): array
    {
        return self::_Get(self::GetChannelKey($key), []);
    }

    /**
     * 通道投递
     *  - 阻塞最大时长受fuse保护，默认60s
     *  - 抢占式锁
     *
     * @param string $key
     * @param mixed $message
     * @param string|int|null $workerId 指定的workerId
     * @param bool $store 在没有监听器时是否进行储存
     * @return bool
     */
    protected static function _ChPublish(string $key, mixed $message, bool $store = true, null|string|int $workerId = null): bool
    {
        $func = __FUNCTION__;
        $params = func_get_args();
        self::_Atomic($key, function () use (
            $key, $message, $func, $params, $store
        ) {
            /**
             * [
             *  workerId = [
             *      'futureId' => futureId,
             *      'value'    => array
             *  ]
             * ]
             */
            $channel = self::_Get($channelName = self::GetChannelKey($key), []);
            // 如果还没有监听器，将数据投入默认
            if (!$channel) {
                if ($store) {
                    $channel['--default--']['value'][] = $message;
                }
            }
            // 否则将消息投入到每个worker的监听器数据中
            else {
                foreach ($channel as $workerId => $item) {
                    if ($store or isset($item['futureId'])) {
                        $channel[$workerId]['value'][] = $message;
                    }
                }
            }

            self::_Set($channelName, $channel);
            return [
                'timestamp' => microtime(true),
                'method'    => $func,
                'params'    => $params,
                'result'    => null
            ];
        }, true);
        return true;
    }

    /**
     * 创建通道监听器
     *  - 同一个进程只能创建一个监听器来监听相同的通道
     *  - 同一个进程可以同时监听不同的通道
     *
     * @param string $key
     * @param string|int $workerId
     * @param Closure $listener = function(string $channelName, string|int $workerId, mixed $message) {}
     * @return bool|int 监听器id
     */
    protected static function _ChCreateListener(string $key, string|int $workerId, Closure $listener): bool|int
    {
        $func = __FUNCTION__;
        $result = false;
        $params = func_get_args();
        $params[2] = '\Closure';
        if (isset(self::$_listeners[$key])) {
            throw new Error("Channel $key listener already exist. ");
        }
        self::_Atomic($key, function () use (
            $key, $workerId, $func, $params, &$result
        ) {
            /**
             * [
             *  workerId = [
             *      'futureId' => futureId,
             *      'value'    => array
             *  ]
             * ]
             */
            $channel = self::_Get($channelName = self::GetChannelKey($key), []);

            // 设置回调
            $channel[$workerId]['futureId'] =
            self::$_listeners[$key] =
            $result = Future::add(function () use ($key, $workerId) {
                // 原子性执行
                self::_Atomic($key, function () use ($key, $workerId) {
                    $channel = self::_Get($channelName = self::GetChannelKey($key), []);
                    if ((!empty($value = $channel[$workerId]['value'] ?? []))) {
                        // 先进先出
                        $msg = array_shift($value);
                        $channel[$workerId]['value'] = $value;
                        call_user_func(self::$_listeners[$key], $key, $workerId, $msg);
                        self::_Set($channelName, $channel);
                    }

                });
            });
            $channel[$workerId]['value'] = [];
            // 如果存在默认数据
            if ($default = $channel['--default--']['value'] ?? []) {
                foreach ($channel as &$item) {
                    array_unshift($item['value'], ...$default);
                }
                unset($channel['--default--']);
            }
            self::_Set($channelName, $channel);
            return [
                'timestamp' => microtime(true),
                'method'    => $func,
                'params'    => $params,
                'result'    => null
            ];
        }, true);
        return $result;
    }

    /**
     * 移除通道监听器
     *
     * @param string $key
     * @param string|int $workerId
     * @param bool $remove 是否移除消息
     * @return void
     */
    protected static function _ChRemoveListener(string $key, string|int $workerId, bool $remove = false): void
    {
        $func = __FUNCTION__;
        $params = func_get_args();
        self::_Atomic($key, function () use (
            $key, $workerId, $func, $params, $remove
        ) {
            if ($id = self::$_listeners[$key] ?? null) {
                Future::del($id);
                if ($remove) {
                    /**
                     * [
                     *  workerId = [
                     *      'futureId' => futureId,
                     *      'value'    => array
                     *  ]
                     * ]
                     */
                    $channel = self::_Get($channelName = self::GetChannelKey($key), []);
                    unset($channel[$workerId]);
                    self::_Set($channelName, $channel);
                }
                unset(self::$_listeners[$key]);

            }

            return [
                'timestamp' => microtime(true),
                'method'    => $func,
                'params'    => $params,
                'result'    => null
            ];
        }, true);
    }
}
