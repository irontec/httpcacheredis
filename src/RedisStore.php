<?php

namespace irontec\HttpCacheRedis;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;

class RedisStore implements StoreInterface
{

    /**
     * @var \irontec\HttpCacheRedis\RedisClient
     */
    protected $_client;

    protected $_digestKeyPrefix;
    protected $_metadataKeyPrefix;
    protected $_lockKey;
    protected $_keyCache;
    protected $_timeOut;

    public function __construct(
        $connectionParams,
        $digestKeyPrefix,
        $lockKey,
        $metadataKeyPrefix,
        $timeOut = false
    )
    {

        $this->_client = new RedisClient($connectionParams);
        $this->_keyCache = new \SplObjectStorage();

        $this->_lockKey = $lockKey;
        $this->_timeOut = $timeOut;
        $this->_digestKeyPrefix= $digestKeyPrefix;
        $this->_metadataKeyPrefix = $metadataKeyPrefix;
    }

    /**
     * Locates a cached Response for the Request provided.
     *
     * @param Request $request A Request instance
     *
     * @return Response|null A Response instance, or null if no cache entry was found
     */
    public function lookup(Request $request): ?Response
    {
        $key = $this->getMetadataKey($request);
        $entries = $this->getMetadata($key);
        if (empty($entries)) {
            return null;
        }

        $match = null;
        foreach ($entries as $entry) {
            if ($this->requestsMatch(
                    isset($entry[1]['vary'][0]) ? $entry[1]['vary'][0] : '',
                    $request->headers->all(),
                    $entry[0]
                )
            ) {
                $match = $entry;
                break;
            }
        }

        if (null === $match) {
            return null;
        }

        list($headers) = array_slice($match, 1, 1);

        $this->_client->createConnection();
        $body = $this->_client->get($headers['x-content-digest'][0]);
        $this->_client->close();

        if ($body) {
            return $this->recreateResponse($headers, $body);
        }

        return null;

    }

    /**
     * Writes a cache entry to the store for the given Request and Response.
     *
     * Existing entries are read and any that match the response are removed. This
     * method calls write with the new list of cache entries.
     *
     * @param Request $request A Request instance
     * @param Response $response A Response instance
     *
     * @return string The key under which the response is stored
     */
    public function write(Request $request, Response $response): string
    {

        $metadataKey = $this->getMetadataKey($request);
        // write the response body to the entity store if this is the original response
        if ($response->headers->has('X-Content-Digest') === false) {

            $digest = $this->generateDigestKey($request);
            if (false === $this->save($digest, $response->getContent())) {
                throw new \RuntimeException('Unable to store the entity.');
            }

            $response->headers->set('X-Content-Digest', $digest);

        }

        // read existing cache entries, remove non-varying, and add this one to the list
        $entries = array();
        $vary = $response->headers->get('vary');
        $requestHeaders = $this->getRequestHeaders($request);
        $metadataKey = $this->getMetadataKey($request);

        foreach ($this->getMetadata($metadataKey) as $entry) {
            if (!isset($entry[1]['vary'][0])) {
                $entry[1]['vary'] = array('');
            }

            if ($vary != $entry[1]['vary'][0] || !$this->requestsMatch($vary, $entry[0], $requestHeaders)) {
                $entries[] = $entry;
            }
        }

        $headers = $this->getResponseHeaders($response);

        unset($headers['age']);

        array_unshift($entries, array($requestHeaders, $headers));

        if (false === $this->save($metadataKey, serialize($entries))) {
            throw new \RuntimeException('Unable to store the metadata.');
        }

        return $metadataKey;
    }

    /**
     * Invalidates all cache entries that match the request.
     *
     * @param Request $request A Request instance
     */
    public function invalidate(Request $request)
    {

        $modified = false;
        $newEntries = array();

        $key = $this->getMetadataKey($request);

        foreach ($this->getMetadata($key) as $entry) {
            //We pass an empty body we only care about headers.
            $response = $this->recreateResponse($entry[1], null);

            if ($response->isFresh()) {
                $response->expire();
                $modified = true;
                $newEntries[] = array(
                    $entry[0],
                    $this->getResponseHeaders($response)
                );
            }

        }

        if ($modified) {
            if (false === $this->save($key, serialize($newEntries))) {
                throw new \RuntimeException('Unable to store the metadata.');
            }
        }

    }

    /**
     * Locks the cache for a given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean|string true if the lock is acquired, the path to the current lock otherwise
     */
    public function lock(Request $request): bool
    {

        $this->_client->createConnection();

        $result = $this->_client->hSetNx(
            $this->_lockKey,
            $this->getMetadataKey($request),
            1
            );

        $this->_client->close();

        return $result == 1;
    }

    /**
     * Releases the lock for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean False if the lock file does not exist or cannot be unlocked, true otherwise
     */
    public function unlock(Request $request): bool
    {

        $this->_client->createConnection();

        $result = $this->_client->hdel(
            $this->_lockKey,
            $this->getMetadataKey($request)
            );

        $this->_client->close();

        return $result == 1;

    }

    /**
     * Returns whether or not a lock exists.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean true if lock exists, false otherwise
     */
    public function isLocked(Request $request): bool
    {

        $this->_client->createConnection();

        $result = $this->_client->hget(
            $this->_lockKey,
            $this->getMetadataKey($request)
            );

        $this->_client->close();

        return $result == 1;

    }

    /**
     * Purges data for the given URL.
     *
     * @param string $url A URL
     *
     * @return Boolean true if the URL exists and has been purged, false otherwise
     */
    public function purge($url): bool
    {

        $request = Request::create($url);

        $this->_client->createConnection();

        $digest = $this->generateDigestKey($request);
        $digestKeys = $this->_client->keys($digest);
        $this->_deleteKeys($digestKeys);

        $metadata = $this->getMetadataKey($request);
        $metadataKeys = $this->_client->keys($metadata);
        $this->_deleteKeys($metadataKeys);

        $this->_client->close();

        return true;

    }

    /**
     * Cleanups storage.
     */
    public function cleanup()
    {

        $this->_client->createConnection();
        $this->_client->del($this->_lockKey);

    }

    /**
     * Returns a cache key for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return string A key for the given Request
     */
    private function getMetadataKey(Request $request): string
    {
        if (isset($this->_keyCache[$request])) {
            return $this->_keyCache[$request];
        }
        $this->_keyCache[$request] = $this->_metadataKeyPrefix . '::' . sha1(
                $request->getSchemeAndHttpHost().$request->getRequestUri()
            );

        return $this->_keyCache[$request];

    }

    /**
     * Persists the Request HTTP headers.
     *
     * @param Request $request A Request instance
     *
     * @return array An array of HTTP headers
     */
    private function getRequestHeaders(Request $request): array
    {
        return $request->headers->all();
    }

    /**
     * Returns content digest for $response.
     *
     * @param Response $response
     *
     * @return string
     */
    protected function generateDigestKey(
        Request $request
        ): string
    {
        return sprintf(
            '%s::%s',
            $this->_digestKeyPrefix,
            md5($request->getSchemeAndHttpHost().$request->getRequestUri())
            );

    }

    private function save($key, $data)
    {

        if (empty($data)) {
            return;
        }

        $this->_client->createConnection();

        $this->_client->set($key, $data);

        if (is_int($this->_timeOut)) {
            $this->_client->setTimeout($key, $this->_timeOut);
        }

        $this->_client->close();

    }

    /**
     * Gets all data associated with the given key.
     *
     * Use this method only if you know what you are doing.
     *
     * @param string $key The store key
     *
     * @return array An array of data associated with the key
     */
    private function getMetadata($key): array
    {

        $entries = $this->load($key);

        if (false === $entries) {
            return array();
        }

        return unserialize($entries);

    }

    /**
     * Loads data for the given key.
     *
     * @param string $key The store key
     *
     * @return string The data associated with the key
     */
    private function load($key): string
    {

        $this->_client->createConnection();

        $values = $this->_client->get($key);

        $this->_client->close();

        return $values;

    }

    /**
     * Determines whether two Request HTTP header sets are non-varying based on
     * the vary response header value provided.
     *
     * @param string $vary A Response vary header
     * @param array $env1 A Request HTTP header array
     * @param array $env2 A Request HTTP header array
     *
     * @return Boolean true if the two environments match, false otherwise
     */
    private function requestsMatch($vary, $env1, $env2): bool
    {

        if (empty($vary)) {
            return true;
        }

        foreach (preg_split('/[\s,]+/', $vary) as $header) {
            $key = strtr(strtolower($header), '_', '-');
            $v1 = isset($env1[$key]) ? $env1[$key] : null;
            $v2 = isset($env2[$key]) ? $env2[$key] : null;
            if ($v1 !== $v2) {
                return false;
            }
        }

        return true;

    }

    private function getResponseHeaders(Response $response)
    {

        $headers = $response->headers->all();
        $headers['X-Status'] = array($response->getStatusCode());

        return $headers;

    }

    private function recreateResponse($headers, $body)
    {

        $status = $headers['X-Status'][0];
        unset($headers['X-Status']);

        return new Response($body, $status, $headers);

    }

    protected function _deleteKeys(array $keys = array()):bool
    {

        if (empty($keys)) {
            return false;
        }

        $prefix = $this->_client->getOption(\Redis::OPT_PREFIX);
        foreach ($keys as $key) {
            $this->_client->del(
                str_replace(
                    $prefix,
                    '',
                    $key
                )
            );
        }

        return true;

    }

}
