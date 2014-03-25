<?php

namespace VMelnik\DoctrineEncryptBundle\Subscribers;

use Doctrine\ORM\Events;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Common\Annotations\Reader;
use \Doctrine\ORM\EntityManager;
use \ReflectionClass;
use \RuntimeException;
use VMelnik\DoctrineEncryptBundle\Encryptors\EncryptorInterface;

/**
 * Doctrine event subscriber which encrypt/decrypt entities
 */
class DoctrineEncryptSubscriber implements EventSubscriber {
    /**
     * Encryptor interface namespace 
     */

    const ENCRYPTOR_INTERFACE_NS = 'VMelnik\DoctrineEncryptBundle\Encryptors\EncryptorInterface';

    /**
     * Encrypted annotation full name
     */
    const ENCRYPTED_ANN_NAME = 'VMelnik\DoctrineEncryptBundle\Configuration\Encrypted';

    /**
     * Encryptor
     * @var EncryptorInterface 
     */
    private $encryptor;

    /**
     * Annotation reader
     * @var Reader
     */
    private $annReader;

    /**
     * Registr to avoid multi decode operations for one entity
     * @var array
     */
    private $decodedRegistry = array();

    /**
     * Initialization of subscriber
     * @param string $encryptorClass  The encryptor class.  This can be empty if 
     * a service is being provided.
     * @param string $secretKey The secret key. 
     * @param EncryptorInterface|NULL $service (Optional)  An EncryptorInterface.
     * This allows for the use of dependency injection for the encrypters.
     */
    public function __construct(Reader $annReader, $encryptorClass, $secretKey, EncryptorInterface $service = NULL) {
        $this->annReader = $annReader;
        if ($service instanceof EncryptorInterface) {
            $this->encryptor = $service;
        } else {
            $this->encryptor = $this->encryptorFactory($encryptorClass, $secretKey);
        }
    }

    /**
     * Listen a prePersist lifecycle event. Checking and encrypt entities
     * which have @Encrypted annotation
     * @param LifecycleEventArgs $args 
     */
    public function prePersist(LifecycleEventArgs $args) {
        $entity = $args->getEntity();
        $this->processFields($entity);
    }

    /**
     * Listen for postPersist lifecycle event. Decode any entity parameters
     * that were just encoded prior to the persist. This way the hydrated entity
     * object remains usable after the persis.
     */
    public function postPersist(LifecycleEventArgs $args) {
        $entity = $args->getEntity();
        if(!$this->hasInDecodedRegistry($entity, $args->getEntityManager())) {
            if($this->processFields($entity, false)) {
                $this->addToDecodedRegistry($entity, $args->getEntityManager());
            }
        }        
    }

    /**
     * Listen a preUpdate lifecycle event. Checking and encrypt entities fields
     * which have @Encrypted annotation. Using changesets to avoid preUpdate event
     * restrictions
     * @param LifecycleEventArgs $args 
     */
    public function preUpdate(PreUpdateEventArgs $args) {
        if (in_array(Events::preUpdate, self::$subscribedEvents)) {
            $reflectionClass = new ReflectionClass($args->getEntity());
            $properties = $reflectionClass->getProperties();
            foreach ($properties as $refProperty) {
                if ($this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME)) {
                    $propName = $refProperty->getName();
            if (!$args->hasChangedField($propName)) continue; // Skip over any unchanged fields
                    $args->setNewValue($propName, $this->encryptor->encrypt($args->getNewValue($propName)));
                }
            }
        }
    }

    /**
     * Listen a postLoad lifecycle event. Checking and decrypt entities
     * which have @Encrypted annotations
     * @param LifecycleEventArgs $args 
     */
    public function postLoad(LifecycleEventArgs $args) {
        if (in_array(Events::postLoad, self::$subscribedEvents)) {
            $entity = $args->getEntity();
            if (!$this->hasInDecodedRegistry($entity, $args->getEntityManager())) {
                if ($this->processFields($entity, false)) {
                    $this->addToDecodedRegistry($entity, $args->getEntityManager());
                }
            }
        }
    }

    protected static $subscribedEvents = array(
        Events::prePersist,
        Events::preUpdate,
        Events::postLoad,
        Events::postPersist
    );

    public static function setSubscribedEvents($events) {
        self::$subscribedEvents = $events;
    }

    /**
     * Realization of EventSubscriber interface method.
     * @return Array Return all events which this subscriber is listening
     */
    public function getSubscribedEvents() {
        return self::$subscribedEvents;
    }

    /**
     * Capitalize string
     * @param string $word
     * @return string
     */
    public static function capitalize($word) {
        if (is_array($word)) {
            $word = $word[0];
        }

        return str_replace(' ', '', ucwords(str_replace(array('-', '_'), ' ', $word)));
    }

    /**
     * Process (encrypt/decrypt) entities fields
     * @param Obj $entity Some doctrine entity
     * @param Boolean $isEncryptOperation If true - encrypt, false - decrypt entity 
     */
    private function processFields($entity, $isEncryptOperation = true) {
        $encryptorMethod = $isEncryptOperation ? 'encrypt' : 'decrypt';
        $reflectionClass = new ReflectionClass($entity);
        $properties = $reflectionClass->getProperties();
        $withAnnotation = false;
        foreach ($properties as $refProperty) {
            if ($this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME)) {
                $withAnnotation = true;
                // we have annotation and if it decrypt operation, we must avoid double decryption
                $propName = $refProperty->getName();
                if ($refProperty->isPublic()) {
		    // Do not try to en/decrypt a null value
		    if (is_null($refProperty->getValue())) continue;
                    $entity->$propName = $this->encryptor->$encryptorMethod($refProperty->getValue());
                } else {
                    $methodName = self::capitalize($propName);
                    if ($reflectionClass->hasMethod($getter = 'get' . $methodName) && $reflectionClass->hasMethod($setter = 'set' . $methodName)) {
			// Do not try to en/decrypt a null value
			if (is_null($entity->$getter())) continue;
                        $currentPropValue = $this->encryptor->$encryptorMethod($entity->$getter());
                        $entity->$setter($currentPropValue);
                    } else {
                        throw new RuntimeException(sprintf("Property %s isn't public and doesn't has getter/setter"));
                    }
                }
            }
        }

        return $withAnnotation;
    }

    /**
     * Encryptor factory. Checks and create needed encryptor
     * @param string $classFullName Encryptor namespace and name
     * @param string $secretKey Secret key for encryptor
     * @return EncryptorInterface
     * @throws RuntimeException 
     */
    private function encryptorFactory($classFullName, $secretKey) {
        $refClass = new \ReflectionClass($classFullName);
        if ($refClass->implementsInterface(self::ENCRYPTOR_INTERFACE_NS)) {
            return new $classFullName($secretKey);
        } else {
            throw new RuntimeException('Encryptor must implements interface EncryptorInterface');
        }
    }

    /**
     * Check if we have entity in decoded registry
     * @param Object $entity Some doctrine entity
     * @param EntityManager $em
     * @return boolean
     */
    private function hasInDecodedRegistry($entity, EntityManager $em) {
        $className = get_class($entity);
        $metadata = $em->getClassMetadata($className);
        $getter = 'get' . self::capitalize($metadata->getIdentifier());

        return isset($this->decodedRegistry[$className][$entity->$getter()]);
    }

    /**
     * Adds entity to decoded registry
     * @param object $entity Some doctrine entity
     * @param EntityManager $em
     */
    private function addToDecodedRegistry($entity, EntityManager $em) {
        $className = get_class($entity);
        $metadata = $em->getClassMetadata($className);
        $getter = 'get' . self::capitalize($metadata->getIdentifier());
        $this->decodedRegistry[$className][$entity->$getter()] = true;
    }

}
