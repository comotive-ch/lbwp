<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace BrevoScoped\Symfony\Component\VarDumper\Cloner;

use BrevoScoped\Symfony\Component\VarDumper\Caster\Caster;
use BrevoScoped\Symfony\Component\VarDumper\Exception\ThrowingCasterException;
/**
 * AbstractCloner implements a generic caster mechanism for objects and resources.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
abstract class AbstractCloner implements ClonerInterface
{
    public static array $defaultCasters = ['__PHP_Incomplete_Class' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\Caster', 'castPhpIncompleteClass'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\CutStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'castStub'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\CutArrayStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'castCutArray'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\ConstStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'castStub'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\EnumStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'castEnum'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\ScalarStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'castScalar'], 'Fiber' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\FiberCaster', 'castFiber'], 'Closure' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castClosure'], 'Generator' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castGenerator'], 'ReflectionType' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castType'], 'ReflectionAttribute' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castAttribute'], 'ReflectionGenerator' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castReflectionGenerator'], 'ReflectionClass' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castClass'], 'ReflectionClassConstant' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castClassConstant'], 'ReflectionFunctionAbstract' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castFunctionAbstract'], 'ReflectionMethod' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castMethod'], 'ReflectionParameter' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castParameter'], 'ReflectionProperty' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castProperty'], 'ReflectionReference' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castReference'], 'ReflectionExtension' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castExtension'], 'ReflectionZendExtension' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castZendExtension'], 'BrevoScoped\Doctrine\Common\Persistence\ObjectManager' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\Doctrine\Common\Proxy\Proxy' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DoctrineCaster', 'castCommonProxy'], 'BrevoScoped\Doctrine\ORM\Proxy\Proxy' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DoctrineCaster', 'castOrmProxy'], 'BrevoScoped\Doctrine\ORM\PersistentCollection' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DoctrineCaster', 'castPersistentCollection'], 'BrevoScoped\Doctrine\Persistence\ObjectManager' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'DOMException' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castException'], 'BrevoScoped\Dom\Exception' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castException'], 'DOMStringList' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castLength'], 'DOMNameList' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castLength'], 'DOMImplementation' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castImplementation'], 'BrevoScoped\Dom\Implementation' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castImplementation'], 'DOMImplementationList' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castLength'], 'DOMNode' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castNode'], 'BrevoScoped\Dom\Node' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castNode'], 'DOMNameSpaceNode' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castNameSpaceNode'], 'DOMDocument' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDocument'], 'BrevoScoped\Dom\XMLDocument' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castXMLDocument'], 'BrevoScoped\Dom\HTMLDocument' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castHTMLDocument'], 'DOMNodeList' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castLength'], 'BrevoScoped\Dom\NodeList' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castLength'], 'DOMNamedNodeMap' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castLength'], 'BrevoScoped\Dom\DTDNamedNodeMap' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castLength'], 'DOMCharacterData' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castCharacterData'], 'BrevoScoped\Dom\CharacterData' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castCharacterData'], 'DOMAttr' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castAttr'], 'BrevoScoped\Dom\Attr' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castAttr'], 'DOMElement' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castElement'], 'BrevoScoped\Dom\Element' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castElement'], 'DOMText' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castText'], 'BrevoScoped\Dom\Text' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castText'], 'DOMDocumentType' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDocumentType'], 'BrevoScoped\Dom\DocumentType' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDocumentType'], 'DOMNotation' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castNotation'], 'BrevoScoped\Dom\Notation' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castNotation'], 'DOMEntity' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castEntity'], 'BrevoScoped\Dom\Entity' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castEntity'], 'DOMProcessingInstruction' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castProcessingInstruction'], 'BrevoScoped\Dom\ProcessingInstruction' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castProcessingInstruction'], 'DOMXPath' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DOMCaster', 'castXPath'], 'XMLReader' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\XmlReaderCaster', 'castXmlReader'], 'ErrorException' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castErrorException'], 'Exception' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castException'], 'Error' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castError'], 'BrevoScoped\Symfony\Bridge\Monolog\Logger' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\Symfony\Component\DependencyInjection\ContainerInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\Symfony\Component\EventDispatcher\EventDispatcherInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\Symfony\Component\HttpClient\AmpHttpClient' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClient'], 'BrevoScoped\Symfony\Component\HttpClient\CurlHttpClient' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClient'], 'BrevoScoped\Symfony\Component\HttpClient\NativeHttpClient' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClient'], 'BrevoScoped\Symfony\Component\HttpClient\Response\AmpResponse' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'BrevoScoped\Symfony\Component\HttpClient\Response\AmpResponseV4' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'BrevoScoped\Symfony\Component\HttpClient\Response\AmpResponseV5' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'BrevoScoped\Symfony\Component\HttpClient\Response\CurlResponse' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'BrevoScoped\Symfony\Component\HttpClient\Response\NativeResponse' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'BrevoScoped\Symfony\Component\HttpFoundation\Request' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castRequest'], 'BrevoScoped\Symfony\Component\Uid\Ulid' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castUlid'], 'BrevoScoped\Symfony\Component\Uid\Uuid' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castUuid'], 'BrevoScoped\Symfony\Component\VarExporter\Internal\LazyObjectState' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castLazyObjectState'], 'BrevoScoped\Symfony\Component\VarDumper\Exception\ThrowingCasterException' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castThrowingCasterException'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\TraceStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castTraceStub'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\FrameStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castFrameStub'], 'BrevoScoped\Symfony\Component\VarDumper\Cloner\AbstractCloner' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\Symfony\Component\ErrorHandler\Exception\FlattenException' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castFlattenException'], 'BrevoScoped\Symfony\Component\ErrorHandler\Exception\SilencedErrorContext' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castSilencedErrorContext'], 'BrevoScoped\Imagine\Image\ImageInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ImagineCaster', 'castImage'], 'BrevoScoped\Ramsey\Uuid\UuidInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\UuidCaster', 'castRamseyUuid'], 'BrevoScoped\ProxyManager\Proxy\ProxyInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ProxyManagerCaster', 'castProxy'], 'PHPUnit_Framework_MockObject_MockObject' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\PHPUnit\Framework\MockObject\MockObject' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\PHPUnit\Framework\MockObject\Stub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\Prophecy\Prophecy\ProphecySubjectInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'BrevoScoped\Mockery\MockInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'PDO' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\PdoCaster', 'castPdo'], 'PDOStatement' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\PdoCaster', 'castPdoStatement'], 'AMQPConnection' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castConnection'], 'AMQPChannel' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castChannel'], 'AMQPQueue' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castQueue'], 'AMQPExchange' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castExchange'], 'AMQPEnvelope' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castEnvelope'], 'ArrayObject' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castArrayObject'], 'ArrayIterator' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castArrayIterator'], 'SplDoublyLinkedList' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castDoublyLinkedList'], 'SplFileInfo' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castFileInfo'], 'SplFileObject' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castFileObject'], 'SplHeap' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castHeap'], 'SplObjectStorage' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castObjectStorage'], 'SplPriorityQueue' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castHeap'], 'OuterIterator' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castOuterIterator'], 'WeakMap' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castWeakMap'], 'WeakReference' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\SplCaster', 'castWeakReference'], 'Redis' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedis'], 'BrevoScoped\Relay\Relay' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedis'], 'RedisArray' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedisArray'], 'RedisCluster' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedisCluster'], 'DateTimeInterface' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DateCaster', 'castDateTime'], 'DateInterval' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DateCaster', 'castInterval'], 'DateTimeZone' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DateCaster', 'castTimeZone'], 'DatePeriod' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DateCaster', 'castPeriod'], 'GMP' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\GmpCaster', 'castGmp'], 'MessageFormatter' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\IntlCaster', 'castMessageFormatter'], 'NumberFormatter' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\IntlCaster', 'castNumberFormatter'], 'IntlTimeZone' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\IntlCaster', 'castIntlTimeZone'], 'IntlCalendar' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\IntlCaster', 'castIntlCalendar'], 'IntlDateFormatter' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\IntlCaster', 'castIntlDateFormatter'], 'Memcached' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\MemcachedCaster', 'castMemcached'], 'BrevoScoped\Ds\Collection' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DsCaster', 'castCollection'], 'BrevoScoped\Ds\Map' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DsCaster', 'castMap'], 'BrevoScoped\Ds\Pair' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DsCaster', 'castPair'], 'BrevoScoped\Symfony\Component\VarDumper\Caster\DsPairStub' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\DsCaster', 'castPairStub'], 'mysqli_driver' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\MysqliCaster', 'castMysqliDriver'], 'CurlHandle' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castCurl'], ':dba' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castDba'], ':dba persistent' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castDba'], 'GdImage' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castGd'], ':gd' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castGd'], ':pgsql large object' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\PgSqlCaster', 'castLargeObject'], ':pgsql link' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\PgSqlCaster', 'castLink'], ':pgsql link persistent' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\PgSqlCaster', 'castLink'], ':pgsql result' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\PgSqlCaster', 'castResult'], ':process' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castProcess'], ':stream' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castStream'], 'OpenSSLCertificate' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castOpensslX509'], ':OpenSSL X.509' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castOpensslX509'], ':persistent stream' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castStream'], ':stream-context' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castStreamContext'], 'XmlParser' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\XmlResourceCaster', 'castXml'], ':xml' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\XmlResourceCaster', 'castXml'], 'RdKafka' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castRdKafka'], 'BrevoScoped\RdKafka\Conf' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castConf'], 'BrevoScoped\RdKafka\KafkaConsumer' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castKafkaConsumer'], 'BrevoScoped\RdKafka\Metadata\Broker' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castBrokerMetadata'], 'BrevoScoped\RdKafka\Metadata\Collection' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castCollectionMetadata'], 'BrevoScoped\RdKafka\Metadata\Partition' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castPartitionMetadata'], 'BrevoScoped\RdKafka\Metadata\Topic' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopicMetadata'], 'BrevoScoped\RdKafka\Message' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castMessage'], 'BrevoScoped\RdKafka\Topic' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopic'], 'BrevoScoped\RdKafka\TopicPartition' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopicPartition'], 'BrevoScoped\RdKafka\TopicConf' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopicConf'], 'BrevoScoped\FFI\CData' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\FFICaster', 'castCTypeOrCData'], 'BrevoScoped\FFI\CType' => ['BrevoScoped\Symfony\Component\VarDumper\Caster\FFICaster', 'castCTypeOrCData']];
    protected int $maxItems = 2500;
    protected int $maxString = -1;
    protected int $minDepth = 1;
    /**
     * @var array<string, list<callable>>
     */
    private array $casters = [];
    /**
     * @var callable|null
     */
    private $prevErrorHandler;
    private array $classInfo = [];
    private int $filter = 0;
    /**
     * @param callable[]|null $casters A map of casters
     *
     * @see addCasters
     */
    public function __construct(?array $casters = null)
    {
        $this->addCasters($casters ?? static::$defaultCasters);
    }
    /**
     * Adds casters for resources and objects.
     *
     * Maps resources or objects types to a callback.
     * Types are in the key, with a callable caster for value.
     * Resource types are to be prefixed with a `:`,
     * see e.g. static::$defaultCasters.
     *
     * @param callable[] $casters A map of casters
     */
    public function addCasters(array $casters): void
    {
        foreach ($casters as $type => $callback) {
            $this->casters[$type][] = $callback;
        }
    }
    /**
     * Sets the maximum number of items to clone past the minimum depth in nested structures.
     */
    public function setMaxItems(int $maxItems): void
    {
        $this->maxItems = $maxItems;
    }
    /**
     * Sets the maximum cloned length for strings.
     */
    public function setMaxString(int $maxString): void
    {
        $this->maxString = $maxString;
    }
    /**
     * Sets the minimum tree depth where we are guaranteed to clone all the items.  After this
     * depth is reached, only setMaxItems items will be cloned.
     */
    public function setMinDepth(int $minDepth): void
    {
        $this->minDepth = $minDepth;
    }
    /**
     * Clones a PHP variable.
     *
     * @param int $filter A bit field of Caster::EXCLUDE_* constants
     */
    public function cloneVar(mixed $var, int $filter = 0): Data
    {
        $this->prevErrorHandler = set_error_handler(function ($type, $msg, $file, $line, $context = []) {
            if (\E_RECOVERABLE_ERROR === $type || \E_USER_ERROR === $type) {
                // Cloner never dies
                throw new \ErrorException($msg, 0, $type, $file, $line);
            }
            if ($this->prevErrorHandler) {
                return ($this->prevErrorHandler)($type, $msg, $file, $line, $context);
            }
            return \false;
        });
        $this->filter = $filter;
        if ($gc = gc_enabled()) {
            gc_disable();
        }
        try {
            return new Data($this->doClone($var));
        } finally {
            if ($gc) {
                gc_enable();
            }
            restore_error_handler();
            $this->prevErrorHandler = null;
        }
    }
    /**
     * Effectively clones the PHP variable.
     */
    abstract protected function doClone(mixed $var): array;
    /**
     * Casts an object to an array representation.
     *
     * @param bool $isNested True if the object is nested in the dumped structure
     */
    protected function castObject(Stub $stub, bool $isNested): array
    {
        $obj = $stub->value;
        $class = $stub->class;
        if (str_contains($class, "@anonymous\x00")) {
            $stub->class = get_debug_type($obj);
        }
        if (isset($this->classInfo[$class])) {
            [$i, $parents, $hasDebugInfo, $fileInfo] = $this->classInfo[$class];
        } else {
            $i = 2;
            $parents = [$class];
            $hasDebugInfo = method_exists($class, '__debugInfo');
            foreach (class_parents($class) as $p) {
                $parents[] = $p;
                ++$i;
            }
            foreach (class_implements($class) as $p) {
                $parents[] = $p;
                ++$i;
            }
            $parents[] = '*';
            $r = new \ReflectionClass($class);
            $fileInfo = $r->isInternal() || $r->isSubclassOf(Stub::class) ? [] : ['file' => $r->getFileName(), 'line' => $r->getStartLine()];
            $this->classInfo[$class] = [$i, $parents, $hasDebugInfo, $fileInfo];
        }
        $stub->attr += $fileInfo;
        $a = Caster::castObject($obj, $class, $hasDebugInfo, $stub->class);
        try {
            while ($i--) {
                if (!empty($this->casters[$p = $parents[$i]])) {
                    foreach ($this->casters[$p] as $callback) {
                        $a = $callback($obj, $a, $stub, $isNested, $this->filter);
                    }
                }
            }
        } catch (\Exception $e) {
            $a = [(Stub::TYPE_OBJECT === $stub->type ? Caster::PREFIX_VIRTUAL : '') . '⚠' => new ThrowingCasterException($e)] + $a;
        }
        return $a;
    }
    /**
     * Casts a resource to an array representation.
     *
     * @param bool $isNested True if the object is nested in the dumped structure
     */
    protected function castResource(Stub $stub, bool $isNested): array
    {
        $a = [];
        $res = $stub->value;
        $type = $stub->class;
        try {
            if (!empty($this->casters[':' . $type])) {
                foreach ($this->casters[':' . $type] as $callback) {
                    $a = $callback($res, $a, $stub, $isNested, $this->filter);
                }
            }
        } catch (\Exception $e) {
            $a = [(Stub::TYPE_OBJECT === $stub->type ? Caster::PREFIX_VIRTUAL : '') . '⚠' => new ThrowingCasterException($e)] + $a;
        }
        return $a;
    }
}
