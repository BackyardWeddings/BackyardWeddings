<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cocorico\MessageBundle\Repository;

use Doctrine\ORM\EntityRepository;
use FOS\MessageBundle\Model\ParticipantInterface;
use FOS\UserBundle\Model\UserInterface;

/**
 * MessageRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class MessageRepository extends EntityRepository
{
    /**
     * Tells how many unread messages this participant has
     *
     * @param ParticipantInterface|UserInterface $participant
     * @param boolean                            $type
     * @return int the number of unread messages
     */
    public function getNbUnreadMessage(ParticipantInterface $participant, $type = false)
    {
        $builder = $this->createQueryBuilder('m');

        $builder
            ->innerJoin('m.metadata', 'mm')
            ->innerJoin('mm.participant', 'p')
            ->where('p.id = :participant_id')
            ->andWhere('m.sender != :sender')
            ->andWhere('mm.isRead = :isRead')
            ->setParameter('participant_id', $participant->getId())
            ->setParameter('sender', $participant->getId())
            ->setParameter('isRead', false, \PDO::PARAM_BOOL);

        // case when needed count of unread messages depending upon the user types
        if ($type) {
            $builder
                ->select(" SUM(CASE WHEN l.user != :user THEN 1 ELSE 0 END) as asker ")
                ->addSelect(" SUM(CASE WHEN l.user = :user THEN 1 ELSE 0 END) as offerer ")
                ->leftJoin('m.thread', 't')
                ->leftJoin('t.listing', 'l')
                ->setParameter('user', $participant);

            $result = $builder->getQuery()->getResult();
        } else {
            // case when needed count of all unread messages for a user
            $builder->select($builder->expr()->count('mm.id'));

            $result = $builder->getQuery()->getSingleScalarResult();
        }

        return $result;
    }
}
