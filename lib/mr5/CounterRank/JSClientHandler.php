<?php

namespace mr5\CounterRank;

// +----------------------------------------------------------------------
// | [counter-rank]
// +----------------------------------------------------------------------
// | Author: Mr.5 <mr5.simple@gmail.com>
// +----------------------------------------------------------------------
// + Datetime: 14-7-7 下午3:33
// +----------------------------------------------------------------------
// + JSClientHandler.php  JS 客户端处理
// +----------------------------------------------------------------------

/**
 *
 * JS 客户端 handler
 * @package mr5\CounterRank
 *
 * @example
 * ``
 * ```php
 * // 控制器参考
 * class ExampleController  extends Controller
 * {
 * protected $token = array(
 * 'articles' => '1234567890JQK',
 * 'comments' => 'abcdefghijk'
 * );
 * protected $redis_host = 'localhost';
 * protected $redis_port = 6379;
 * protected $namespace = 'project_name';
 * protected $increaseStepSize = 1;
 *
 * // @var JSClientHandler|null
 * protected $handlerInstance = null;
 * public function __construct()
 * {
 * $this->handlerInstance = new JSClientHandler($this->redis_host, $this->redis_port, $this->namespace, $this->token, $this->increaseStepSize);
 * }
 *
 * // 读取
 * public function getAction()
 * {
 * $this->handlerInstance->handleGet($_REQUEST['token'], $_REQUEST['group'], $_REQUEST['keys'], $_REQUEST['callback']);
 * }
 *
 * // 递增
 * public function increaseAction()
 * {
 * $this->handlerInstance->handleIncrease($_REQUEST['token'], $_REQUEST['group'], $_REQUEST['keys'], $_REQUEST['callback']);
 * }
 *
 * // 排名
 * public function rankAction()
 * {
 * $this->handlerInstance->handleRank($_REQUEST['token'], $_REQUEST['group'], $_REQUEST['type'], $_REQUEST['limit'], $_REQUEST['callback']);
 * }
 *
 * // 最高的十个数据
 * public function top10Action()
 * {
 * $this->handlerInstance->handleTop10($_REQUEST['token'], $_REQUEST['group'], $_REQUEST['callback']);
 *
 * }
 *
 * // 最低的十个数据
 * public function down10Action()
 * {
 * $this->handlerInstance->handleDown10($_REQUEST['token'], $_REQUEST['group'], $_REQUEST['callback']);
 *
 * }
 * }
 * ```
 */

class JSClientHandler
{
    /**
     * @var array 供验证的 token
     * @see JSClientHandler::__construct()
     */
    private $tokens = array();
    /**
     * @var string 命名空间
     */
    private $namespace = null;
    /**
     * @var int 递增的步长
     */
    private $increaseStepSize = 1;
    /**
     * @var CounterRank
     */
    private $counterRank = null;
    /**
     * @var string 多个键间的分隔符
     */
    private $keysSeparator = '_';
    /**
     * @var bool
     */
    private $isOutput = true;
    /**
     * @var string 最后一次输出的数据
     */
    private $lastOutputData = '';
    /**
     * @var string 回调函数验证正则，防止 XSS
     */
    private $callbackNameRegex = '/^[a-zA-z\$_][\w_\$]*$/';

    /**
     *
     *
     * @var \Closure
     */
    private $tokenVerifier = null;

    /**
     * @param string $redis_host redis host
     * @param int $redis_port redis port
     * @param string $namespace 顶级命名空间
     * @param array $tokens 用于与客户端访问权限控制，格式是  array('groupName'=>'token', 'groupName1'=>'token1', 'groupNameN'=>'tokenN')
     * @param int $increaseStepSize 递增步长
     * @param string $keysSeparator 多个键间的分隔符，默认是 _
     * @param bool $useFloat 是否使用浮点数，默认不使用
     * @param bool $isOutput 是否输出，默认输出。不管是否输出，都可以通过 getLastOutput() 获取最后一次的输出数据
     */
    public function __construct(
        $redis_host,
        $redis_port,
        $namespace,
        array $tokens,
        $increaseStepSize = 1,
        $keysSeparator = '_',
        $useFloat = false,
        $isOutput = true
    ) {
        $this->tokens = $tokens;
        $this->namespace = trim($namespace);
        $this->increaseStepSize = $increaseStepSize;
        $this->keysSeparator = $keysSeparator;
        $this->isOutput = $isOutput;

        $this->counterRank = new CounterRank($redis_host, $redis_port, $namespace, '', $useFloat);
    }

    /**
     * 获取当前使用的 CounterRank 对象
     *
     * @return CounterRank
     */
    public function getCounterRankInstance()
    {
        return $this->counterRank;
    }

    /**
     * 处理 get 操作
     * 路径设计参考：/client.js/:token/:group/:item/get?callback=:callback
     *
     * @param string $userHash 客户端用户提交的 token
     * @param string $userGroupName 客户端用户提交的分组名
     * @param string $userKeys 客户端用户提交的 item key
     * @param string $userCallback 客户端用户提交的 JsonP 回调函数，可留空，留空则直接输出值
     */
    public function handleGet($userHash, $userGroupName, $userKeys, $userCallback = '')
    {
        $this->baseInfoCheck($userHash, $userGroupName, 'get', $userKeys);


        $keys = explode($this->keysSeparator, $userKeys);
        if (is_array($keys) && count($keys) > 1) {
            $items = $this->counterRank->mGet($keys);
        } else {
            $items = $this->counterRank->get($userKeys);
        }

        $this->outputResult($items, $userCallback);
    }

    /**
     * 递增处理
     * URL 设计参考： /client.js/:token/:group/:item/increase?callback=:callback
     *
     * @param string $userHash
     * @param string $userGroupName
     * @param string $userKeys
     * @param string $userCallback
     */
    public function handleIncrease($userHash, $userGroupName, $userKeys, $userCallback = '')
    {
        $this->baseInfoCheck($userHash, $userGroupName, 'increase', $userKeys);

        $keys = explode($this->keysSeparator, $userKeys);

        if (is_array($keys) && count($keys) > 1) {
            $items = $this->counterRank->mIncrease($keys, $this->increaseStepSize);
        } else {
            $items = $this->counterRank->increase($keys[0], $this->increaseStepSize);
        }

        $this->outputResult($items, $userCallback);

    }

    /**
     * 排名处理
     * 路径设计参考：/client.js/:token/:group/rank/:limit?type=:type&callback=:callback
     *
     * @param $userHash
     * @param $userGroupName
     * @param $userType
     * @param $userLimit
     * @param $userCallback
     */
    public function handleRank($userHash, $userGroupName, $userType, $userLimit, $userCallback = '')
    {
        $this->baseInfoCheck($userHash, $userGroupName, 'rank');

        $this->outputResult($this->counterRank->rank($userLimit, $userType), $userCallback);
    }

    /**
     * top10 handler
     * 路径设计参考：/client.js/:token/:group/top10?callback=:callback
     *
     * @param string $userHash
     * @param string $userGroupName
     * @param string $userCallback
     */
    public function handleTop10($userHash, $userGroupName, $userCallback = '')
    {
        $this->handleRank($userHash, $userGroupName, 'desc', 10, $userCallback);
    }

    /**
     * down 10 handler
     * 路径设计参考：/client.js/:token/:group/down10?callback=:callback
     *
     * @param string $userHash
     * @param string $userGroupName
     * @param string $userCallback
     */
    public function handleDown10($userHash, $userGroupName, $userCallback)
    {
        $this->handleRank($userHash, $userGroupName, 'asc', 10, $userCallback);
    }

    /**
     * 基础信息检查：token 验证，设置分组名等等
     *
     * @param string $userHash
     * @param string $userGroupName
     * @param string $operation
     * @param string $keys
     */
    private function baseInfoCheck($userHash, $userGroupName, $operation, $keys = null)
    {
        if (!$userHash) {
            $this->outputError('token 未指定');
        }
        if (!isset($this->tokens[$userGroupName]) || !$this->verifyToken(
            $operation,
            $userHash,
            $this->tokens[$userGroupName],
            $userGroupName,
            $keys
        )
        ) {
            $this->outputError('token 不正确');
        }
        $userGroupName = trim($userGroupName);

        if (!$userGroupName) {
            $this->outputError('分组名未指定');
        }

        $this->counterRank->setGroupName($userGroupName);

    }

    /**
     * 输出结果
     *
     * @param array|string $items
     * @param string $userCallback
     */
    private function outputResult($items, $userCallback = '')
    {
        if (is_array($items)) {
            $items = json_encode($items);
        }
        if ($items === false || $items === null) {
            $items = 'null';
        }
        if ($userCallback) {
            if (!preg_match($this->callbackNameRegex, $userCallback)) {
                $this->outputError('invalid callback name.');
            }
            $this->output("{$userCallback}({$items});");
        } else {
            $this->output($items);
        }
    }

    /**
     * 输出信息
     *
     * @param $string
     */
    private function output($string)
    {
        if ($this->isOutput) {
            echo $string;
        }
        $this->lastOutputData = $string;
    }

    /**
     * 获取最后一次的输出
     *
     * @return string
     */
    public function getLastOutput()
    {
        return $this->lastOutputData;
    }

    /**
     * 输出错误信息，并结束后续执行
     *
     * @param $message
     */
    private function outputError($message)
    {
        die("throw new Error('{$message}');");
    }

    /**
     * token 验证
     *
     * @param string $operation 当前执行的操作
     * @param string $userToken 用户提交的 token
     * @param string $token 当前 group 的token
     * @param string $group 当前分组名
     * @param string $key 当前操作的 $key
     *
     * @return bool
     */
    protected function verifyToken($operation, $userToken, $token, $group, $key)
    {
        if ($this->tokenVerifier instanceof \Closure) {
            return call_user_func_array($this->tokenVerifier, func_get_args());
        } else {
            return $userToken === $token;
        }
    }

    /**
     * 设置 token 验证器，该验证器接收的参数与 verifyToken 相同，验证通过返回 true ，否则返回 false。
     * @see JSClientHandler::verifyToke()
     *
     * @param callable $tokenVerifier
     */
    public function setTokenVerifier(\Closure $tokenVerifier)
    {
        $this->tokenVerifier = $tokenVerifier;
    }

    /**
     * 移除 token 验证器
     */
    public function removeTokenVerifier()
    {
        $this->tokenVerifier = null;
    }
}
