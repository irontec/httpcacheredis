<?php

namespace irontec\HttpCacheRedis;

use \Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;
use \Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\Response;
use \Symfony\Component\HttpKernel\HttpCache\StoreInterface;

class HttpCacheRedis
    extends HttpCache
{

    /**
     * {@inheritDoc}
     * @see \Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache::createStore()
     */
    public function createStore(): StoreInterface
    {

        return new RedisStore(
            $this->getConnectionParams(),
            $this->getDigestKeyPrefix(),
            $this->getLockKey(),
            $this->getMetadataKeyPrefix(),
            $this->getTimeout()
            );

    }

    /**
     * @return array
     */
    public function getConnectionParams()
    {
        return array(
            'host' => 'localhost',
            'port' => 6379,
            'database' => 0,
            'options' => array()
        );
    }

    /**
     * @return string
     */
    public function getDigestKeyPrefix()
    {
        return 'DigestKey';
    }

    /**
     * @return string
     */
    public function getLockKey()
    {
        return 'Lock';
    }

    /**
     * @return string
     */
    public function getMetadataKeyPrefix()
    {
        return 'MetaData';
    }

    /**
     * @return integer
     */
    public function getTimeout()
    {
        return 120;
    }

    /**
     * @param Request $request
     * @param string $catch
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function invalidate(
        Request $request,
        $catch = false
    ): Response
    {

        if ('PURGE' !== $request->getMethod()) {
            return parent::invalidate($request, $catch);
        }

        $response = new Response();
        if ($this->getStore()->purge($request->getUri())) {
            $response->setStatusCode(200, 'Purged');
        } else {
            $response->setStatusCode(201, 'Not found');
        }

        return $response;

    }

}
