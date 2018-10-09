<?php
/**
 * This file is part of the login-cidadao project or it's bundles.
 *
 * (c) Guilherme Donato <guilhermednt on github>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NFG\BadgesBundle\Event;

use LoginCidadao\BadgesControlBundle\Model\AbstractBadgesEventSubscriber;
use LoginCidadao\BadgesControlBundle\Event\EvaluateBadgesEvent;
use LoginCidadao\BadgesControlBundle\Event\ListBearersEvent;
use NFG\BadgesBundle\Model\Badge;
use Symfony\Component\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManager;
use LoginCidadao\BadgesControlBundle\Model\BadgeInterface;
use PROCERGS\LoginCidadao\CoreBundle\Helper\MeuRSHelper;

class BadgesSubscriber extends AbstractBadgesEventSubscriber
{
    /** @var TranslatorInterface */
    protected $translator;

    /** @var EntityManager */
    protected $em;

    /** @var MeuRSHelper */
    protected $meuRSHelper;

    public function __construct(TranslatorInterface $translator,
                                EntityManager $em, MeuRSHelper $meuRSHelper)
    {
        $this->translator  = $translator;
        $this->em          = $em;
        $this->meuRSHelper = $meuRSHelper;

        $namespace = 'nfg';
        $this->setName($namespace);

        $this->registerBadge('nfg_access_lvl',
            $translator->trans("$namespace.nfg_access_lvl.description", array(),
                'badges'), array('counter' => 'countNfgAccessLvl'));
        $this->registerBadge('voter_registration',
            $translator->trans("$namespace.voter_registration.description",
                array(), 'badges'), array('counter' => 'countVoterRegistration'));
    }

    public function onBadgeEvaluate(EvaluateBadgesEvent $event)
    {
        $this->checkNfg($event);
    }

    public function onListBearers(ListBearersEvent $event)
    {
        $filterBadge = $event->getBadge();
        if ($filterBadge instanceof BadgeInterface) {
            $countMethod = $this->badges[$filterBadge->getName()]['counter'];
            $count       = $this->{$countMethod}($filterBadge->getData());

            $event->setCount($filterBadge, $count);
        } else {
            foreach ($this->badges as $name => $badge) {
                $countMethod = $badge['counter'];
                $count       = $this->{$countMethod}();
                $badge       = new Badge($this->getName(), $name);

                $event->setCount($badge, $count);
            }
        }
    }

    protected function checkNfg(EvaluateBadgesEvent $event)
    {
        $person = $event->getPerson();
        $meuRSPerson = $this->meuRSHelper->getPersonMeuRS($person);
        if (method_exists($meuRSPerson, 'getNfgProfile') && $meuRSPerson->getNfgProfile()) {
            $event->registerBadge($this->getBadge('nfg_access_lvl',
                    $meuRSPerson->getNfgProfile()->getAccessLvl()));

            if ($meuRSPerson->getNfgProfile()->getVoterRegistrationSit() == 1) {
                $event->registerBadge($this->getBadge('voter_registration', true));
            }
        }
    }

    protected function getBadge($name, $data)
    {
        if (array_key_exists($name, $this->getAvailableBadges())) {
            return new Badge($this->getName(), $name, $data);
        } else {
            throw new Exception("Badge $name not found in namespace {$this->getName()}.");
        }
    }

    protected function countNfgAccessLvl($filterData = null)
    {
        $empty = array(
            "1" => 0,
            "2" => 0,
            "3" => 0
        );

        $query = $this->em->getRepository('PROCERGSLoginCidadaoCoreBundle:PersonMeuRS')
            ->createQueryBuilder('m')
            ->select('n.accessLvl, COUNT(n) total')
            ->join('PROCERGSLoginCidadaoCoreBundle:NfgProfile', 'n', 'WITH',
                'm.nfgProfile = n')
            ->groupBy('n.accessLvl');

        if ($filterData !== null) {
            $query->andWhere('n.accessLvl = :filterData')
                ->setParameters(compact('filterData'));
        }

        $count = $query->getQuery()->getResult();
        if (!empty($count)) {
            $original = $count;
            $count    = $empty;
            foreach ($original as $line) {
                $level         = $line['accessLvl'];
                $count[$level] = $line['total'];
            }
            return $count;
        } else {
            return $empty;
        }
    }

    protected function countVoterRegistration()
    {
        return $this->em->getRepository('PROCERGSLoginCidadaoCoreBundle:PersonMeuRS')
                ->createQueryBuilder('m')
                ->select('COUNT(p)')
                ->join('PROCERGSLoginCidadaoCoreBundle:NfgProfile', 'n', 'WITH',
                    'm.nfgProfile = n')
                ->join('LoginCidadaoCoreBundle:Person', 'p', 'WITH',
                    'm.person = p')
                ->andWhere('n.voterRegistrationSit > 0')
                ->getQuery()->getSingleScalarResult();
    }
}
