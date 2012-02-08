<?php

/**
 * @see Zend_Cache_Backend
 */
#require_once 'Zend/Cache/Backend.php';

/**
 * @see Zend_Cache_Backend_ExtendedInterface
 */
#require_once 'Zend/Cache/Backend/ExtendedInterface.php';

/**
 * Redis adapter for Zend_Cache
 * 
 * @author Soin Stoiana
 * @author Colin Mollenhour
 * @version 0.0.1
 */
class Zend_Cache_Backend_Redis extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{

    const SET_IDS  = 'zc:ids';
    const SET_TAGS = 'zc:tags';

    const PREFIX_DATA     = 'zc:d:';
    const PREFIX_MTIME    = 'zc:m:';
    const PREFIX_TAG_IDS  = 'zc:ti:';
    const PREFIX_ID_TAGS  = 'zc:it:';

    /** @var Redis */
    protected $_redis;

    /** @var bool */
    protected $_notMatchingTags = FALSE;

    /** @var bool */
    protected $_exactMtime = FALSE;

    /**
     * Contruct Zend_Cache Redis backend
     * @param array $options
     * @return \Zend_Cache_Backend_Redis
     */
    public function __construct($options = array())
    {
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        }

        if( empty($options['server']) ) {
            Zend_Cache::throwException('Redis \'server\' not specified.');
        }

        if( empty($options['port']) ) {
            Zend_Cache::throwException('Redis \'port\' not specified.');
        }

        // Use redisent if specified or redis module does not exist
        if( ! extension_loaded('redis') || ( isset($options['use_redisent']) && $options['use_redisent'])) {
            require_once 'redisent/redisent.php';
            $this->_redis = new RedisentWrap($options['server'], $options['port'], TRUE);
        }
        else {
            $this->_redis = new Redis;
            if( ! $this->_redis->connect($options['server'], $options['port']) ) {
                Zend_Cache::throwException("Could not connect to Redis server {$options['server']}:{$options['port']}");
            }
        }

        if ( ! empty($options['database'])) {
            $this->_redis->select( (int) $options['database']) or Zend_Cache::throwException('The redis database could not be selected.');
        }

        if ( isset($options['notMatchingTags']) ) {
            $this->_notMatchingTags = (bool) $options['notMatchingTags'];
        }

        if ( isset($options['exactMtime']) ) {
            $this->_exactMtime = (bool) $options['exactMtime'];
        }

        if ( isset($options['automatic_cleaning_factor']) ) {
            $this->_options['automatic_cleaning_factor'] = (int) $options['automatic_cleaning_factor'];
        } else {
            $this->_options['automatic_cleaning_factor'] = 20000;
        }
    }
    /**
     * Load value with given id from cache
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $data = $this->_redis->get(self::PREFIX_DATA . $id);
        if($data === NULL) {
            return FALSE;
        }
        return $data;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return bool|int False if record is not available or "last modified" timestamp of the available cache record
     */
    public function test($id)
    {
        if($this->_exactMtime) {
            $mtime = $this->_redis->get(self::PREFIX_MTIME . $id);
            return ($mtime ? $mtime : FALSE);
        }
        else {
            $exists = $this->_redis->exists(self::PREFIX_DATA . $id);
            return ($exists ? time() : FALSE);
        }
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  bool|int $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        if(!is_array($tags)) $tags = array($tags);

        $lifetime = $this->getLifetime($specificLifetime);

        // Set the data
        if ($lifetime) {
            $result = $this->_redis->setex(self::PREFIX_DATA . $id, $lifetime, $data);
        } else {
            $result = $this->_redis->set(self::PREFIX_DATA . $id, $data);
        }

        if ( $result == 'OK' )
        {
            // Set the modified time
            if($this->_exactMtime) {
                if ($lifetime) {
                    $this->_redis->setex(self::PREFIX_MTIME . $id, $lifetime, time());
                } else {
                    $this->_redis->set(self::PREFIX_MTIME . $id, time());
                }
            }

            if (count($tags) > 0)
            {
                // Update the list with all the tags
                $this->_redisVariadic('sAdd', self::SET_TAGS, $tags);

                // Update the list of tags for this id, expire at same time as data key
                $this->_redis->del(self::PREFIX_ID_TAGS . $id);
                $this->_redisVariadic('sAdd', self::PREFIX_ID_TAGS . $id, $tags);
                if ($lifetime) {
                    $this->_redis->expire(self::PREFIX_ID_TAGS . $id, $lifetime);
                }

                // Update the id list for each tag
                foreach($tags as $tag)
                {
                    $this->_redis->sAdd(self::PREFIX_TAG_IDS . $tag, $id);
                }
            }

            // Update the list with all the ids
            if($this->_notMatchingTags) {
                $this->_redis->sAdd(self::SET_IDS, $id);
            }

            return TRUE;
        }

        return FALSE;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        // Remove data
        $result = $this->_redis->del( self::PREFIX_DATA . $id );

        // Remove mtime
        if($this->_exactMtime) {
            $this->_redis->del( self::PREFIX_MTIME . $id );
        }

        // Remove id from list of all ids
        if($this->_notMatchingTags) {
            $this->_redis->srem( self::SET_IDS, $id );
        }

        // Get list of tags for this id
        $tags = $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id);

        // Update the id list for each tag
        foreach($tags as $tag) {
            $this->_redis->srem(self::PREFIX_TAG_IDS . $tag, $id);
        }

        // Remove list of tags
        $this->_redis->del( self::PREFIX_ID_TAGS . $id );

        return (bool) $result;
    }

    protected function _removeByNotMatchingTags($tags)
    {
        $ids = $this->getIdsNotMatchingTags($tags);
        if( ! $ids) {
            return;
        }

        // Remove data
        $this->_redisVariadic('del', $this->_preprocessIds($ids));

        // Remove mtimes
        if($this->_exactMtime) {
            $this->_redisVariadic('del', $this->_preprocessMtimes($ids));
        }

        // Remove ids from list of all ids
        if($this->_notMatchingTags) {
            $this->_redisVariadic('srem', self::SET_IDS, $ids);
        }

        // Update the id list for each tag
        $tagsToClean = $this->_redisVariadic('sUnion', $this->_preprocessIdTags($ids) );
        foreach($tagsToClean as $tag) {
            $this->_redisVariadic('srem', self::PREFIX_TAG_IDS . $tag, $ids);
        }

        // Remove tag lists for all ids
        $this->_redisVariadic('del', $this->_preprocessIdTags($ids));
    }

    protected function _removeByMatchingTags($tags)
    {
        $ids = $this->getIdsMatchingTags($tags);
        if($ids)
        {
            // Remove data
            $this->_redisVariadic('del', $this->_preprocessIds($ids));

            // Remove mtimes
            if($this->_exactMtime) {
                $this->_redisVariadic('del', $this->_preprocessMtimes($ids));
            }

            // Remove ids from tags not cleared
            $idTags = $this->_preprocessIdTags($ids);
            $otherTags = (array) $this->_redisVariadic('sUnion', $idTags);
            $otherTags = array_diff($otherTags, $tags);
            foreach($otherTags as $tag) {
                $this->_redisVariadic('srem', self::PREFIX_TAG_IDS . $tag, $ids);
            }

            // Remove tag lists for all ids
            $this->_redisVariadic('del', $idTags);

            // Remove ids from list of all ids
            if($this->_notMatchingTags) {
                $this->_redisVariadic('srem', self::SET_IDS, $ids);
            }
        }
    }

    protected function _removeByMatchingAnyTags($tags)
    {
        $ids = $this->getIdsMatchingAnyTags($tags);
        if($ids)
        {
            // Remove data
            $this->_redisVariadic('del', $this->_preprocessIds($ids));

            // Remove mtimes
            if($this->_exactMtime) {
                $this->_redisVariadic('del', $this->_preprocessMtimes($ids));
            }

            // Remove ids from tags not cleared
            $idTags = $this->_preprocessIdTags($ids);
            $otherTags = (array) $this->_redisVariadic('sUnion', $idTags );
            $otherTags = array_diff($otherTags, $tags);
            foreach($otherTags as $tag) {
                $this->_redisVariadic('srem', self::PREFIX_TAG_IDS . $tag, $ids);
            }

            // Remove tag lists for all ids
            $this->_redisVariadic('del', $idTags);

            // Remove ids from list of all ids
            if($this->_notMatchingTags) {
                $this->_redisVariadic('srem', self::SET_IDS, $ids);
            }
        }

        // Remove tag id lists
        $this->_redisVariadic('del', $this->_preprocessTagIds($tags));

        // Remove tags from list of tags
        $this->_redisVariadic('srem', self::SET_TAGS, $tags);
    }

    protected function _collectGarbage()
    {
        // Clean up expired keys from tag id set and global id set
        $exists = array();
        $tags = (array) $this->_redis->sMembers(self::SET_TAGS);
        foreach($tags as $tag) {
            $tagMembers = $this->_redis->sMembers(self::PREFIX_TAG_IDS . $tag);
            if( ! count($tagMembers)) continue;
            $expired = array();
            foreach($tagMembers as $id) {
                if( ! isset($exists[$id])) {
                    $exists[$id] = $this->_redis->exists($id);
                }
                if( ! $exists[$id]) {
                    $expired[] = $id;
                }
            }
            if( ! count($expired)) continue;

            if(count($expired) == count($tagMembers)) {
                $this->_redis->del(self::PREFIX_TAG_IDS . $tag);
                $this->_redis->sRem(self::SET_TAGS, $tag);
            } else {
                $this->_redisVariadic('sRem', self::PREFIX_TAG_IDS . $tag, $expired);
            }
            if($this->_notMatchingTags) {
                $this->_redisVariadic('sRem', self::SET_IDS, $expired);
            }
        }

        // Clean up global list of ids for ids with no tag
        if($this->_notMatchingTags) {
            // TODO
        }
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => unsupported
     * 'matchingTag'    => supported
     * 'notMatchingTag' => supported
     * 'matchingAnyTag' => supported
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if( $tags && ! is_array($tags)) {
            $tags = array($tags);
        }

        if($mode == Zend_Cache::CLEANING_MODE_ALL) {
            return ($this->_redis->flushDb() == 'OK');
        }

        if($mode == Zend_Cache::CLEANING_MODE_OLD) {
            $this->_collectGarbage();
            return TRUE;
        }

        if( ! count($tags)) {
            return TRUE;
        }

        $result = TRUE;

        switch ($mode)
        {
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:

                $this->_removeByMatchingTags($tags);
                break;

            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:

                $this->_removeByNotMatchingTags($tags);
                break;

            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:

                $this->_removeByMatchingAnyTags($tags);
                break;

            default:
                Zend_Cache::throwException('Invalid mode for clean() method: '.$mode);
        }
        return (bool) $result;
    }

    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return TRUE;
    }

    /**
     * Set the frontend directives
     *
     * @param  array $directives Assoc of directives
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setDirectives($directives)
    {
        parent::setDirectives($directives);
        $lifetime = $this->getLifetime(false);
        if ($lifetime > 2592000) {
            Zend_Cache::throwException('Redis backend has a limit of 30 days (2592000 seconds) for the lifetime');
        }
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        if($this->_notMatchingTags) {
            return (array) $this->_redis->sMembers(self::SET_IDS);
        } else {
            $keys = $this->_redis->keys(self::PREFIX_DATA . '*');
            $prefixLen = strlen(self::PREFIX_DATA);
            foreach($keys as &$key) {
                $key = substr($key, $prefixLen);
            }
            return $keys;
        }
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return (array) $this->_redis->sMembers(self::SET_TAGS);
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        return (array) $this->_redisVariadic('sInter', $this->_preprocessTagIds($tags) );
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a negated logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        if( ! $this->_notMatchingTags) {
            Zend_Cache::throwException("notMatchingTags is currently disabled.");
        }
        return (array) $this->_redisVariadic('sDiff', self::SET_IDS, $this->_preprocessTagIds($tags) );
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        return (array) $this->_redisVariadic('sUnion', $this->_preprocessTagIds($tags));
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        return 0;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        $mtime = $this->test($id);
        if( ! $mtime) {
            return FALSE;
        }
        $ttl = $this->_redis->ttl(self::PREFIX_DATA . $id);
        $tags = (array) $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id );

        return array(
            'expire' => time() + $ttl,
            'tags' => $tags, 
            'mtime' => $mtime,
        );
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $ttl = $this->_redis->ttl(self::PREFIX_DATA . $id);
        if ($ttl != -1) {
            $expireAt = time() + $ttl + $extraLifetime;
            $result = $this->_redis->expireAt(self::PREFIX_DATA . $id, $expireAt);
            if($result) {
                if($this->_exactMtime) {
                    $this->_redis->expireAt(self::PREFIX_MTIME . $id, $expireAt);
                }
                $this->_redis->expireAt(self::PREFIX_ID_TAGS . $id, $expireAt);
            }
            return (bool) $result;
        }
        return false;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => ($this->_options['automatic_cleaning_factor'] > 0),
            'tags'               => true,
            'expired_read'       => false,
            'priority'           => false,
            'infinite_lifetime'  => true,
            'get_list'           => $this->_notMatchingTags,
        );
    }

    protected function _preprocess(&$item, $index, $prefix)
    {
        $item = $prefix . $item;
    }

    protected function _preprocessItems($items, $prefix)
    {
        array_walk( $items, array($this, '_preprocess'), $prefix);
        return $items;
    }

    protected function _preprocessIds($ids)
    {
        return $this->_preprocessItems($ids, self::PREFIX_DATA);
    }

    protected function _preprocessMtimes($ids)
    {
        return $this->_preprocessItems($ids, self::PREFIX_MTIME);
    }

    protected function _preprocessIdTags($ids)
    {
        return $this->_preprocessItems($ids, self::PREFIX_ID_TAGS);
    }

    protected function _preprocessTagIds($tags)
    {
        return $this->_preprocessItems($tags, self::PREFIX_TAG_IDS);
    }

    protected function _redisVariadic($command, $arg1, $args = NULL)
    {
		$ret = null;
    	if(is_array($arg1)) {
            $args = $arg1;
        } else {
            array_unshift($args, $arg1);
        }
        $arguments = print_r($args, true);
        $redisCommand = array_shift($args);
        $args_txt = print_r($args, true);
		// fpalomo
        // Hack to avoid error notice
        if (empty($args)){
        	$args = NULL;
        }
		try{
			
			if($command=="del" and is_array($args)){
				foreach ($args as $keyToJoin){
					$ret = call_user_func_array(
							array($this->_redis,
									$command),
							array( $redisCommand ,
									$keyToJoin ) );
				}
			}

			
			else if($command=="sUnion" and is_array($args)){
				foreach ($args as $keyToJoin){
					$ret = call_user_func_array(
							array($this->_redis,
									$command),
							array( $redisCommand ,
									$keyToJoin ) );
				}
			}else{
				
		        $ret = call_user_func_array( 
		        		array($this->_redis, 
		        				$command), 
		        		array( $redisCommand , 
		        				$args ) );
			}
			return $ret;
		}catch(Exception $e){
			Mage::log("$command");
			Mage::log("$redisCommand");
			Mage::log($args_txt);
			throw $e;
		}
    }

    
    /**
     * Required to pass unit tests
     *
     * @param  string $id
     * @return void
     */
    public function ___expire($id)
    {
        $this->_redis->del(self::PREFIX_DATA . $id);
        if($this->_exactMtime) {
            $this->_redis->del(self::PREFIX_MTIME . $id);
        }
        $this->_redis->del(self::PREFIX_ID_TAGS . $id);
    }

}
