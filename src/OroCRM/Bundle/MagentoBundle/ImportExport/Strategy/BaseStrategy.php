<?php

namespace OroCRM\Bundle\MagentoBundle\ImportExport\Strategy;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\UnitOfWork;

use Oro\Bundle\AddressBundle\Entity\AbstractAddress;
use Oro\Bundle\AddressBundle\Entity\AbstractTypedAddress;
use Oro\Bundle\BatchBundle\Item\InvalidItemException;
use Oro\Bundle\ImportExportBundle\Context\ContextAwareInterface;
use Oro\Bundle\ImportExportBundle\Context\ContextInterface;
use Oro\Bundle\ImportExportBundle\Strategy\Import\ImportStrategyHelper;
use Oro\Bundle\ImportExportBundle\Strategy\StrategyInterface;
use OroCRM\Bundle\MagentoBundle\Entity\Region;
use Oro\Bundle\AddressBundle\Entity\Region as BAPRegion;

abstract class BaseStrategy implements StrategyInterface, ContextAwareInterface
{
    /** @var ImportStrategyHelper */
    protected $strategyHelper;

    /** @var ContextInterface */
    protected $context;

    /** @var array */
    protected $regionsCache = [];

    /** @var array */
    protected $countriesCache = [];

    /** @var array */
    protected $mageRegionsCache = [];

    /**
     * @param ImportStrategyHelper $strategyHelper
     */
    public function __construct(ImportStrategyHelper $strategyHelper)
    {
        $this->strategyHelper = $strategyHelper;
    }

    /**
     * @param ContextInterface $context
     */
    public function setImportExportContext(ContextInterface $context)
    {
        $this->context = $context;
    }

    /**
     * @param mixed        $entity
     * @param string       $entityName
     * @param string|array $criteria
     * @param array        $excludedProperties
     *
     * @return mixed
     */
    protected function findAndReplaceEntity($entity, $entityName, $criteria = 'id', $excludedProperties = [])
    {
        if (is_array($criteria)) {
            $existingEntity = $this->getEntityByCriteria($criteria, $entity);
        } else {
            $existingEntity = $this->getEntityOrNull($entity, $criteria, $entityName);

        }

        if ($existingEntity) {
            $this->strategyHelper->importEntity($existingEntity, $entity, $excludedProperties);
            $entity = $existingEntity;
        } else {
            $entity->setId(null);
        }

        return $entity;
    }

    /**
     * @param object $entity
     *
     * @return null|object
     */
    protected function validateAndUpdateContext($entity)
    {
        // validate entity
        $validationErrors = $this->strategyHelper->validateEntity($entity);
        if ($validationErrors) {
            $this->context->incrementErrorEntriesCount();
            $this->strategyHelper->addValidationErrors($validationErrors, $this->context);

            return null;
        }

        // increment context counter
        if ($entity->getId()) {
            $this->context->incrementUpdateCount();
        } else {
            $this->context->incrementAddCount();
        }

        return $entity;
    }

    /**
     * @param mixed  $entity
     * @param string $entityIdField
     * @param string $entityClass
     *
     * @return object|null
     */
    protected function getEntityOrNull($entity, $entityIdField, $entityClass)
    {
        $existingEntity = null;
        $entityId       = $entity->{'get' . ucfirst($entityIdField)}();

        if ($entityId) {
            $existingEntity = $this->getEntityByCriteria([$entityIdField => $entityId], $entityClass);
        }

        return $existingEntity ? : null;
    }

    /**
     * @param array         $criteria
     * @param object|string $entity object to get class from or class name
     *
     * @return object
     */
    protected function getEntityByCriteria(array $criteria, $entity)
    {
        if (is_object($entity)) {
            $entityClass = ClassUtils::getClass($entity);
        } else {
            $entityClass = $entity;
        }

        return $this->getEntityRepository($entityClass)->findOneBy($criteria);
    }

    /**
     * @param $entityName
     *
     * @return \Doctrine\ORM\EntityManager
     */
    protected function getEntityManager($entityName)
    {
        return $this->strategyHelper->getEntityManager($entityName);
    }

    /**
     * @param string $entityName
     *
     * @return EntityRepository
     */
    protected function getEntityRepository($entityName)
    {
        return $this->strategyHelper->getEntityManager($entityName)->getRepository($entityName);
    }

    /**
     * @param $entity
     *
     * @return object
     */
    protected function merge($entity)
    {
        $em = $this->getEntityManager(ClassUtils::getClass($entity));
        if ($em->getUnitOfWork()->getEntityState($entity) !== UnitOfWork::STATE_MANAGED) {
            $entity = $em->merge($entity);
        }

        return $entity;
    }

    /**
     * @param AbstractAddress $address
     * @param int             $mageRegionId
     *
     * @return $this
     *
     * @throws InvalidItemException
     */
    protected function updateAddressCountryRegion(AbstractAddress $address, $mageRegionId)
    {
        $countryCode = $address->getCountry()->getIso2Code();

        $country = $this->getAddressCountryByCode($address, $countryCode);
        $address->setCountry($country);

        if (!empty($mageRegionId) && empty($this->mageRegionsCache[$mageRegionId])) {
            $this->mageRegionsCache[$mageRegionId] = $this->getEntityByCriteria(
                ['regionId' => $mageRegionId],
                'OroCRM\Bundle\MagentoBundle\Entity\Region'
            );
        }

        if (!empty($this->mageRegionsCache[$mageRegionId])) {
            /** @var Region $mageRegion */
            $mageRegion   = $this->mageRegionsCache[$mageRegionId];
            $combinedCode = $mageRegion->getCombinedCode();
            $regionCode = $mageRegion->getCode();

            if (empty($this->regionsCache[$combinedCode])) {
                $this->regionsCache[$combinedCode] = $this->loadRegionByCode($combinedCode, $countryCode, $regionCode);
            }

            // no region found in system db for corresponding magento region, use region text
            if (empty($this->regionsCache[$combinedCode])) {
                $address->setRegion(null);
            } else {
                $this->regionsCache[$combinedCode] = $this->merge($this->regionsCache[$combinedCode]);
                $address->setRegion($this->regionsCache[$combinedCode]);
                $address->setRegionText(null);
            }
        } elseif ($address->getRegionText()) {
            $address->setRegion(null);
        } else {
            throw new InvalidItemException('Unable to handle region for address', [$address]);
        }

        return $this;
    }

    /**
     * @param string $combinedCode
     * @param string $countryCode
     * @param string $code
     * @return BAPRegion
     */
    protected function loadRegionByCode($combinedCode, $countryCode, $code)
    {
        $regionClass = 'Oro\Bundle\AddressBundle\Entity\Region';
        $countryClass = 'Oro\Bundle\AddressBundle\Entity\Country';

        // Simply search region by combinedCode
        $region = $this->getEntityByCriteria(
            array(
                'combinedCode' => $combinedCode
            ),
            $regionClass
        );
        if (!$region) {
            // Some region codes in magento are filled by region names
            $entityManager = $this->getEntityManager($countryClass);
            $country = $entityManager->getReference($countryClass, $countryCode);
            $region = $this->getEntityByCriteria(
                array(
                    'country' => $country,
                    'name' => $combinedCode
                ),
                $regionClass
            );
        }
        if (!$region) {
            // Some numeric regions codes may be padded by 0 in ISO format and not padded in magento
            // As example FR-1 in magento and FR-01 in ISO
            $region = $this->getEntityByCriteria(
                array(
                    'combinedCode' =>
                        BAPRegion::getRegionCombinedCode(
                            $countryCode,
                            str_pad($code, 2, '0', STR_PAD_LEFT)
                        )
                ),
                $regionClass
            );
        }

        return $region;
    }

    /**
     * @param AbstractTypedAddress $address
     *
     * @return $this
     */
    protected function updateAddressTypes(AbstractTypedAddress $address)
    {
        // update address type
        $types = $address->getTypeNames();
        if (empty($types)) {
            return $this;
        }

        $address->getTypes()->clear();
        $loadedTypes = $this->getEntityRepository('OroAddressBundle:AddressType')->findBy(['name' => $types]);

        foreach ($loadedTypes as $type) {
            $address->addType($type);
        }

        return $this;
    }

    /**
     * @param AbstractAddress $address
     * @param string          $countryCode
     *
     * @throws InvalidItemException
     * @return object
     */
    protected function getAddressCountryByCode(AbstractAddress $address, $countryCode)
    {
        $this->countriesCache[$countryCode] = empty($this->countriesCache[$countryCode])
            ? $this->findAndReplaceEntity(
                $address->getCountry(),
                'Oro\Bundle\AddressBundle\Entity\Country',
                'iso2Code',
                ['iso2Code', 'iso3Code', 'name']
            )
            : $this->merge($this->countriesCache[$countryCode]);

        if (empty($this->countriesCache[$countryCode])) {
            throw new InvalidItemException(sprintf('Unable to find country by code "%s"', $countryCode), []);
        }

        return $this->countriesCache[$countryCode];
    }
}
