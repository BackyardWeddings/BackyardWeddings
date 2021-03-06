<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cocorico\GeoBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;

/**
 * DepartmentRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class DepartmentRepository extends EntityRepository
{
    /**
     * @param $name
     * @param $area
     * @return mixed|null
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function findOneByNameAndArea($name, $area)
    {

        $queryBuilder = $this->createQueryBuilder('d')
            ->addSelect("d")
            ->leftJoin('d.translations', 't')
            ->where('t.name = :name')
            ->andWhere('d.area = :area')
            ->setParameter('name', $name)
            ->setParameter('area', $area);

        try {
            return $queryBuilder->getQuery()->getOneOrNullResult();
        } catch (NonUniqueResultException $e) {
            return null;
        }

    }
}
