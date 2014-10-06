<?php
/**
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the new BSD license.
 *
 * @package     Mangroove
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 * @author      David Zeller <me@zellerda.com>
 * @license     http://www.opensource.org/licenses/BSD-3-Clause New BSD license
 * @since       1.0
 */
class Mongroove_Query
{
    const TYPE_FIND = 1;
    const TYPE_FIND_AND_UPDATE = 2;
    const TYPE_FIND_AND_REMOVE = 3;
    const TYPE_INSERT = 4;
    const TYPE_UPDATE = 5;
    const TYPE_REMOVE = 6;
    const TYPE_GROUP = 7;
    const TYPE_MAP_REDUCE = 8;
    const TYPE_DISTINCT = 9;
    const TYPE_GEO_NEAR = 10;
    const TYPE_COUNT = 11;

    /**
     * The Collection instance.
     * @var Mongroove_Collection
     */
    protected $collection;

    /**
     * Query structure generated by the Builder class.
     * @var array
     */
    protected $query;

    /**
     *
     * @var Mongroove_Cursor
     */
    protected $cursor;

    /**
     * Query options
     * @var array
     */
    protected $options;

    /**
     * Constructor.
     *
     * @param Mongroove_Collection $collection
     * @param array $query
     * @param array $options
     * @throws InvalidArgumentException if query type is invalid
     */
    public function __construct(Mongroove_Collection $collection, array $query, array $options)
    {
        switch($query['type'])
        {
            case self::TYPE_FIND:
            case self::TYPE_FIND_AND_UPDATE:
            case self::TYPE_FIND_AND_REMOVE:
            case self::TYPE_INSERT:
            case self::TYPE_UPDATE:
            case self::TYPE_REMOVE:
            case self::TYPE_GROUP:
            case self::TYPE_MAP_REDUCE:
            case self::TYPE_DISTINCT:
            case self::TYPE_GEO_NEAR:
            case self::TYPE_COUNT:
                break;
            default:
                throw new InvalidArgumentException('Invalid query type: ' . $query['type']);
        }

        $this->collection = $collection;
        $this->query = $query;
        $this->options = $options;
    }

    /**
     * Retrieve cursor
     *
     * @return Mongroove_Cursor
     */
    public function getCursor()
    {
        switch($this->query['type'])
        {
            case self::TYPE_FIND:
            case self::TYPE_GROUP:
            case self::TYPE_MAP_REDUCE:
            case self::TYPE_DISTINCT:
            case self::TYPE_GEO_NEAR:
                break;

            default:
                throw new BadMethodCallException('Iterator would not be returned for query type: ' . $this->query['type']);
        }

        if($this->cursor === null)
        {
            $cursor = $this->execute();

            if(!$cursor instanceof Mongroove_Cursor)
            {
                throw new UnexpectedValueException('Mongroove_Cursor was not returned from executed query');
            }

            $this->cursor = $cursor;
        }

        return $this->cursor;
    }

    /**
     * Return the query structure.
     *
     * @return array
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Retrieves current collection
     *
     * @return Mongroove_Collection
     */
    public function getCollection()
    {
        return $this->collection;
    }

    /**
     * Return the query type.
     *
     * @return integer
     */
    public function getType()
    {
        return $this->query['type'];
    }

    /**
     * Execute the query and return its results as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->getCursor()->toArray();
    }

    /**
     * Execute the query and return its result.
     *
     * The return value will vary based on the query type. Commands with results
     * (e.g. aggregate, inline mapReduce) may return an ArrayIterator. Other
     * commands and operations may return a status array or a boolean, depending
     * on the driver's write concern. Queries and some mapReduce commands will
     * return a Cursor.
     *
     * @return Mongroove_Cursor|array|boolean
     */
    public function execute()
    {
        $options = $this->options;

        switch($this->query['type'])
        {
            case self::TYPE_FIND:
                $cursor = $this->getCollection()->find(
                    $this->query['query'],
                    isset($this->query['select']) ? $this->query['select'] : array()
                );
                return $this->prepareCursor($cursor);

            case self::TYPE_FIND_AND_UPDATE:
                return $this->getCollection()->findAndUpdate(
                    $this->query['query'],
                    $this->query['new_obj'],
                    array_merge($options, $this->getQueryOptions('new', 'select', 'sort', 'upsert'))
                );

            case self::TYPE_FIND_AND_REMOVE:
                return $this->getCollection()->findAndRemove(
                    $this->query['query'],
                    array_merge($options, $this->getQueryOptions('select', 'sort'))
                );

            case self::TYPE_INSERT:
                return $this->getCollection()->insert($this->query['new_obj'], $options);

            case self::TYPE_UPDATE:
                return $this->getCollection()->update(
                    $this->query['query'],
                    $this->query['new_obj'],
                    array_merge($options, $this->getQueryOptions('multiple', 'upsert'))
                );

            case self::TYPE_REMOVE:
                return $this->getCollection()->remove($this->query['query'], $options);

            case self::TYPE_GROUP:
                if(!empty($this->query['query']))
                {
                    $options['cond'] = $this->query['query'];
                }

                $parameters = array(
                    $this->query['group']['keys'],
                    $this->query['group']['initial'],
                    $this->query['group']['reduce'],
                    array_merge($options, $this->query['group']['options'])
                );

                return $this->withReadPreference($this->getCollection()->getDatabase(), 'group', $parameters);

            case self::TYPE_MAP_REDUCE:
                if(isset($this->query['limit']))
                {
                    $options['limit'] = $this->query['limit'];
                }

                $parameters = array(
                    $this->query['mapReduce']['map'],
                    $this->query['mapReduce']['reduce'],
                    $this->query['mapReduce']['out'],
                    $this->query['query'],
                    array_merge($options, $this->query['mapReduce']['options'])
                );

                $results = $this->withReadPreference($this->getCollection()->getDatabase(), 'mapReduce', $parameters);
                return ($results instanceof MongoCursor) ? $this->prepareCursor($results) : $results;

            case self::TYPE_DISTINCT:
                $parameters = array(
                    $this->query['distinct'],
                    $this->query['query'],
                    $options
                );

                return $this->withReadPreference($this->getCollection()->getDatabase(), 'distinct', $parameters);

            case self::TYPE_GEO_NEAR:
                if(isset($this->query['limit']))
                {
                    $options['num'] = $this->query['limit'];
                }

                $parameters = array(
                    $this->query['geoNear']['near'],
                    $this->query['query'],
                    array_merge($options, $this->query['geoNear']['options'])
                );

                return $this->withReadPreference($this->getCollection()->getDatabase(), 'near', $parameters);

            case self::TYPE_COUNT:
                $parameters = array(
                    $this->query['query']
                );

                return $this->withReadPreference($this->getCollection()->getDatabase(), 'count', $parameters);

            default:
                return false;
                break;
        }
    }

    /**
     * Prepare the Cursor returned by {@link Mongroove_Query::execute()}.
     *
     * This method will apply cursor options present in the query structure
     * array. The Cursor may also be wrapped with an EagerCursor.
     *
     * @param MongoCursor $cursor
     * @return Mongroove_Cursor
     */
    protected function prepareCursor(MongoCursor $cursor)
    {
        if(isset($this->query['readPreference']))
        {
            $cursor->setReadPreference($this->query['readPreference'], $this->query['readPreferenceTags']);
        }

        foreach($this->getQueryOptions('hint', 'immortal', 'limit', 'skip', 'slaveOkay', 'sort') as $key => $value)
        {
            $cursor->$key($value);
        }

        if(!empty($this->query['snapshot']))
        {
            $cursor->snapshot();
        }

        return new Mongroove_Cursor($this->getCollection(), $cursor);
    }

    /**
     * Returns an array containing the specified keys and their values from the
     * query array, provided they exist and are not null.
     *
     * @param string $key,... One or more option keys to be read
     * @return array
     */
    protected function getQueryOptions(/* $key, ... */)
    {
        return array_filter(
            array_intersect_key($this->query, array_flip(func_get_args())),
            function($value) { return $value !== null; }
        );
    }

    /**
     * Executes a closure with a temporary read preference on a database or
     * collection.
     *
     * @param Mongroove_Database $database
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    protected function withReadPreference($database, $method, $parameters = array())
    {
        $result = '';

        if(!isset($this->query['readPreference']))
        {
            return call_user_func_array(array($this->getCollection(), $method), $parameters);
        }

        $prevReadPref = $database->getReadPreference();
        $database->setReadPreference($this->query['readPreference'], $this->query['readPreferenceTags']);

        try
        {
            $result = call_user_func_array(array($this->getCollection(), $method), $parameters);
        }
        catch (Exception $e)
        {
        }

        $prevTags = !empty($prevReadPref['tagsets']) ? $prevReadPref['tagsets'] : null;
        $database->setReadPreference($prevReadPref['type'], $prevTags);

        if(isset($e))
        {
            throw $e;
        }

        return $result;
    }
}