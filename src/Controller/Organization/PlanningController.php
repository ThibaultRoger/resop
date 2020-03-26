<?php

declare(strict_types=1);

namespace App\Controller\Organization;

use App\Domain\AvailabilitiesDomain;
use App\Domain\DatePeriodCalculator;
use App\Domain\SkillSetDomain;
use App\Entity\AvailabilityInterface;
use App\Entity\CommissionableAsset;
use App\Entity\User;
use App\Form\Type\PlanningSearchType;
use App\Repository\AvailabilityRepositoryInterface;
use App\Repository\CommissionableAssetAvailabilityRepository;
use App\Repository\CommissionableAssetRepository;
use App\Repository\UserAvailabilityRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/planning", name="planning", methods={"GET"})
 */
class PlanningController extends AbstractController
{
    private UserRepository $userRepository;
    private CommissionableAssetRepository $assetRepository;
    private UserAvailabilityRepository $userAvailabilityRepository;
    private CommissionableAssetAvailabilityRepository $assetAvailabilityRepository;
    private SkillSetDomain $skillSetDomain;

    public function __construct(
        UserRepository $userRepository,
        CommissionableAssetRepository $assetRepository,
        UserAvailabilityRepository $userAvailabilityRepository,
        CommissionableAssetAvailabilityRepository $assetAvailabilityRepository,
        SkillSetDomain $skillSetDomain
    ) {
        $this->userRepository = $userRepository;
        $this->assetRepository = $assetRepository;
        $this->userAvailabilityRepository = $userAvailabilityRepository;
        $this->assetAvailabilityRepository = $assetAvailabilityRepository;
        $this->skillSetDomain = $skillSetDomain;
    }

    public function __invoke(Request $request): Response
    {
        if (!$request->query->has('from')) {
            $request->query->set('from', (new \DateTimeImmutable('monday this week'))->format('Y-m-d\T00:00:00'));
        }

        if (!$request->query->has('to')) {
            $from = new \DateTimeImmutable($request->query->get('from', 'monday this week'));
            $request->query->set('to', $from->add(new \DateInterval('P1W'))->format('Y-m-d\T00:00:00'));
        }

        $form = $this->container->get('form.factory')->createNamed('', PlanningSearchType::class, [], ['method' => 'GET', 'attr' => ['autocomplete' => 'off']]);
        $form->handleRequest($request);

        $data = $form->getData();
        if (!isset($data['from'], $data['to'])) {
            // This may happen if the passed date is invalid. TODO check it before, the format must be 2020-03-30T00:00:00
            throw $this->createNotFoundException();
        }

        $periodCalculator = DatePeriodCalculator::createRoundedToDay($data['from'], new \DateInterval(AvailabilitiesDomain::SLOT_INTERVAL), $data['to']);

        $users = $data['hideUsers'] ?? false ? [] : $this->userRepository->findByFilters($data);
        $assets = $data['hideAssets'] ?? false ? [] : $this->assetRepository->findByFilters($data);
        $usersAvailabilities = $this->prepareAvailabilities($this->userAvailabilityRepository, $users, $periodCalculator);
        $assetsAvailabilities = $this->prepareAvailabilities($this->assetAvailabilityRepository, $assets, $periodCalculator);

        return $this->render('organization/planning/planning.html.twig', [
            'form' => $form->createView(),
            'periodCalculator' => $periodCalculator,
            'availabilities' => $this->splitAvailabilities($usersAvailabilities, $assetsAvailabilities),
            'assetsTypes' => CommissionableAsset::TYPES,
            'usersSkills' => $this->skillSetDomain->getSkillSet(),
            'importantSkills' => $this->skillSetDomain->getImportantSkills(),
            'importantSkillsToDisplay' => $this->skillSetDomain->getSkillsToDisplay(),
        ]);
    }

    private function prepareAvailabilities(AvailabilityRepositoryInterface $repository, array $availabilitables, DatePeriodCalculator $periodCalculator): array
    {
        $slots = self::parseRawSlots($repository->loadRawDataForEntity($availabilitables, $periodCalculator->getFrom(), $periodCalculator->getTo()));

        $result = [];
        foreach ($availabilitables as $availabilitable) {
            $intervalAvailabilities = [];

            /** @var \DateTime $from */
            foreach ($periodCalculator->getPeriod() as $from) {
                $to = (clone $from)->add($periodCalculator->getPeriod()->interval);
                $existingSlot = $slots[$availabilitable->getId()][$from->format('Y-m-d H:i')] ?? [];
                // TODO Check the end time, just in case
                $intervalAvailabilities[] = [
                    'from' => $from,
                    'to' => $to,
                    'status' => $existingSlot['status'] ?? AvailabilityInterface::STATUS_UNKNOW,
                ];
            }

            $result[] = [
                'entity' => $availabilitable,
                'availabilities' => $intervalAvailabilities,
            ];
        }

        return $result;
    }

    private static function parseRawSlots(array $rawSlots): array
    {
        $slots = [];
        foreach ($rawSlots as $slot) {
            $slotStart = $slot['startTime'] ?? null;
            if (!$slotStart instanceof \DateTimeInterface) {
                continue;
            }
            $slots[$slot['user_id'] ?? $slot['asset_id'] ?? 0][$slotStart->format('Y-m-d H:i')] = $slot;
        }

        return $slots;
    }

    private function splitAvailabilities(array $usersAvailabilities, array $assetsAvailabilities): array
    {
        $result = []; // Ordered associative array

        // Assets
        foreach (CommissionableAsset::getTypesKeys() as $type) {
            $result[$type] = [];
        }

        /** @var CommissionableAsset[] $item */
        foreach ($assetsAvailabilities as $item) {
            $result[$item['entity']->type][] = $item;
        }

        // Users
        $importantSkills = $this->skillSetDomain->getImportantSkills();

        foreach ($importantSkills as $skill) {
            $result[$skill] = []; // Ordered associative array
        }

        /** @var User[] $item */
        foreach ($usersAvailabilities as $item) {
            foreach ($importantSkills as $skill) {
                if (in_array($skill, $item['entity']->skillSet, true)) {
                    $result[$skill][] = $item;
                    continue 2;
                }
            }
            $result['others'][] = $item;
        }

        return array_filter($result);
    }
}
