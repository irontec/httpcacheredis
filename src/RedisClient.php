<?php

namespace irontec\HttpCacheRedis;

class RedisClient
{

    /**
     * @var \Redis
     */
    protected $_redis;

    /**
     * @var string
     */
    protected $_host = '127.0.0.1';

    /**
     * @var integer
     */
    protected $_port = 6379;

    /**
     * @var string|null
     */
    protected $_password = NULL;

    /**
     * @var integer
     */
    protected $_database = 0;

    /**
     * @var array
     */
    protected $_options = array();

    /**
     * @var array
     */
    protected $_paramsValids = array(
        'host',
        'port',
        'password',
        'database',
        'options'
    );

    public function __construct(array $params)
    {

        $this->_redis = new \Redis();

        foreach ($params as $key => $val) {
            if (in_array($key, $this->_paramsValids)) {
                $paramSetter = '_' . $key;
                $this->$paramSetter = $val;
            }
        }

    }

    public function createConnection()
    {

        $this->_redis->connect(
            $this->_host,
            $this->_port
        );

        if (!is_null($this->_password)) {
            $this->_redis->auth($this->_password);
        }

        if (is_integer($this->_database)) {
            $this->_redis->select($this->_database);
        }

        if (!empty($this->_options)) {
            foreach ($this->_options as $key => $option) {
                $this->_redis->setOption($key, $option);
            }
        }

        return $this->_redis;

    }

    public function getOption(int $option = 0)
    {
        return $this->_redis->getOption($option);
    }

    public function __call($name, array $arguments)
    {

        switch (strtolower($name)) {
            case 'connect':
            case 'open':
            case 'pconnect':
            case 'popen':
            case 'setoption':
            case 'getoption':
            case 'auth':
            case 'select':
                return false;
        }

        $result = call_user_func_array(
            array($this->_redis, $name),
            $arguments
        );

        return $result;

    }

    public function __destroy()
    {
        $this->_redis->close();
    }

}
