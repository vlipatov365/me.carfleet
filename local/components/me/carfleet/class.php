<?php

use Bitrix\Iblock\Elements\CarsSectionTable;
use Bitrix\Iblock\Iblock;
use Bitrix\Iblock\IblockTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

class MeCarFleet extends CBitrixComponent
{
    private const CAR_FLEET_IBLOCK_CODE = 'carfleet';
    private const RESERVATION_IBLOCK_CODE = 'reservation_car';
    private const TIME_FORMAT = 'Y-m-d H:i:s';
    private Iblock $carFleetIblock;
    private Iblock $reservationIblock;
    private ?DateTime $timeFrom;
    private ?DateTime $timeTo;

    /**
     * @param $component
     *
     * @throws LoaderException
     */
    public function __construct($component = null)
    {
        $this->initContext();
        parent::__construct($component);
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function executeComponent(): void
    {
        $this->timeFrom = $this->request->get('TIME_FROM')
            ? $this->prepareTime($this->request->get('TIME_FROM'))
            : null;
        $this->timeTo = $this->request->get('TIME_TO')
            ? $this->prepareTime($this->request->get('TIME_TO'))
            : null;

        if ($this->request->get('CAR_ID')) {
            $this->arResult['NEW_RESERVATION'] = $this->addReservation();
        }

        $this->arResult['CAR_FLEET'] = $this->getAvailableCars();

        $this->includeComponentTemplate();
    }

    /**
     * @param $arParams
     *
     * @return array
     * @throws SystemException
     */
    public function onPrepareComponentParams($arParams): array
    {
        if (isset($arParams['CAR_FLEET_IBLOCK_ID'])) {
            $this->carFleetIblock = Iblock::wakeUp((int) $arParams['CAR_FLEET_IBLOCK_ID']);
        } else {
            $this->carFleetIblock = Iblock::wakeUp($this->getCarFleetIblockIdByCode());
        }

        if (isset($arParams['RESERVATION_IBLOCK_ID'])) {
            $this->reservationIblock = Iblock::wakeUp((int) $arParams['RESERVATION_IBLOCK_ID']);
        } else {
            $this->reservationIblock = Iblock::wakeUp($this->getReservationIblockIdByCode());
        }

        return parent::onPrepareComponentParams($arParams);
    }

    /**
     * @param string $time
     *
     * @return DateTime
     * @throws SystemException
     * @throws ObjectException
     */
    private function prepareTime(string $time): DateTime
    {
        return new DateTime($time, self::TIME_FORMAT);
    }

    /**
     * @return array
     * @throws SystemException
     * @throws ArgumentException
     * @throws ObjectPropertyException
     */
    private function getAvailableCars(): array
    {
        $availableCars = [];
        $carFleetQuery = $this->carFleetIblock->getEntityDataClass()::query()
            ->registerRuntimeField(
                'CATEGORY',
                [
                    'data_type' => CarsSectionTable::class,
                    'reference' => ['=ref.ID' => 'this.IBLOCK_SECTION_ID'],
                    'join_type' => Join::TYPE_RIGHT,
                ]
            )
            ->setSelect([
                'ID',
                'NAME',
                'IBLOCK_SECTION_ID',
                'STATE_NUMBER.VALUE',
                'DRIVER.VALUE',
                'CATEGORY.NAME',
                'CATEGORY.UF_AVAILABLE_FOR',
            ]);
        $userWorkPosition = $this->getUserPosition();
        if ($userWorkPosition) {
            $carFleetQuery->setFilter([
                'CATEGORY.UF_AVAILABLE_FOR' => $this->getUserPosition(),
            ]);
        }
        if ($this->timeFrom || $this->timeTo) {
            $this->setTimeRange($carFleetQuery);
        }

        $carFleetCollection = $carFleetQuery->fetchCollection();

        foreach ($carFleetCollection as $car) {
            $availableCars[] = [
                'ID' => $car->getId(),
                'NAME' => $car->getName(),
                'IBLOCK_SECTION_ID' => $car->getIblockSectionId(),
                'STATE_NUMBER' => $car->getStateNumber()->getValue(),
                'DRIVER_ID' => $this->getDriver(
                    (int) $car->getDriver()?->getValue()
                ),
                'CATEGORY' => $car->get('CATEGORY')->getName(),
            ];
        }

        return $availableCars;
    }

    /**
     * @param Query $carFleetQuery
     *
     * @return void
     * @throws ArgumentException
     * @throws SystemException
     */
    private function setTimeRange(Query &$carFleetQuery): void
    {
        if ($this->timeFrom && $this->timeTo) {
            $carFleetQuery->whereNotExists(
                $this->reservationIblock->getEntityDataClass()::query()
                    ->setSelect(['RESERVED_CAR.VALUE'])
                    ->where(
                        Query::filter()
                            ->logic('or')
                            ->whereBetween(
                                'ACTIVE_FROM',
                                $this->timeFrom,
                                $this->timeTo
                            )
                            ->whereBetween(
                                'ACTIVE_TO',
                                $this->timeFrom,
                                $this->timeTo
                            )
                            ->where(
                                Query::filter()
                                    ->where('ACTIVE_FROM', '<=', $this->timeFrom)
                                    ->where('ACTIVE_TO', '>=', $this->timeTo)
                            )
                    )

            );

            return;
        }

        if ($this->timeFrom && !$this->timeTo) {
            $carFleetQuery->whereNotExists(
                $this->reservationIblock->getEntityDataClass()::query()
                    ->setSelect(['RESERVED_CAR.VALUE'])
                    ->where('ACTIVE_FROM', '<=', $this->timeFrom)
            );

            return;
        }

        if (!$this->timeFrom && $this->timeTo) {
            $carFleetQuery->whereNotExists(
                $this->reservationIblock->getEntityDataClass()::query()
                    ->setSelect(['RESERVED_CAR.VALUE'])
                    ->where('ACTIVE_TO', '>=', $this->timeTo)
            );
        }
    }

    /**
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws ArgumentException
     */
    private function getDriver(int $driverId): array
    {
        if ($driverId === 0) {
            return [];
        }

        static $drivers = [];

        if (array_key_exists($driverId, $drivers)) {
            return $drivers[$driverId];
        }
        static $drivers = [];

        $driver = UserTable::query()
            ->setSelect(['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME'])
            ->where('ID', '=', $driverId)
            ->setCacheTtl(3600)
            ->fetch();

        $drivers[$driverId] = $driver ?? [];

        return $drivers[$driverId];
    }

    /**
     * @return int
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getCarFleetIblockIdByCode(): int
    {
        $iblockId = (int) IblockTable::query()
            ->setSelect(['ID'])
            ->where('CODE', '=', self::CAR_FLEET_IBLOCK_CODE)
            ->setCacheTtl(3600)
            ->fetch()['ID'];

        if ($iblockId === 0) {
            throw new SystemException(Loc::getMessage('ME_CARFLEET_CARFLEET_IBLOCK_NOT_FOUND'));
        }

        return $iblockId;
    }

    /**
     * @return int
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getReservationIblockIdByCode(): int
    {
        $iblockId = (int) IblockTable::query()
            ->setSelect(['ID'])
            ->where('CODE', '=', self::RESERVATION_IBLOCK_CODE)
            ->setCacheTtl(3600)
            ->fetch()['ID'];

        if ($iblockId === 0) {
            throw new SystemException(Loc::getMessage('ME_CARFLEET_RESERVATION_IBLOCK_NOT_FOUND'));
        }

        return $iblockId;
    }

    /**
     * @return void
     * @throws LoaderException
     */
    public function initContext(): void
    {
        Loader::requireModule('iblock');
        require __DIR__ . '/include.php';
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function addReservation(): array
    {
        $carId = $this->request->get('CAR_ID');
        if (!$carId) {
            return [
                'SUCCESS' => false,
                'MESSAGE' => Loc::getMessage('ME_CARFLEET_RESERVATION_NO_CAR_ID')
            ];
        }

        if (!$this->carIsAvailable((int) $carId)) {
            return [
                'SUCCESS' => false,
                'MESSAGE' => Loc::getMessage('ME_CARFLEET_RESERVATION_CAR_RESERVED')
            ];
        }

        $iblockElement = new CIblockElement();

        $result = $iblockElement->add([
            'NAME' => $this->getNewReservationName(),
            'IBLOCK_ID' => $this->getReservationIblockIdByCode(),
            'ACTIVE_FROM' => $this->timeFrom,
            'ACTIVE_TO' => $this->timeTo,
            'PROPERTY_VALUES' => [
                'RESERVED_CAR' => $carId,
            ]
        ]);

        return [
            'SUCCESS' => empty($iblockElement->getLastError()),
            'RESERVATION_ID' => $result,
        ];
    }

    /**
     * Правильнее добавить модуль, вынести метод в хелперы и реализовать событие на обновление/добавление инфоблока.
     *
     * @return string
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getNewReservationName(): string
    {
        $lastReservation = $this->reservationIblock->getEntityDataClass()::query()
            ->setSelect(['NAME'])
            ->setOrder(['DATE_CREATE' => 'DESC'])
            ->setLimit(1)
            ->fetch();

        if ($lastReservation) {
            preg_match(
                Loc::getMessage('ME_CARFLEET_RESERVATION_REG_EXP'),
                $lastReservation['NAME'],
                $matches
            );
            if ($matches[1]) {
                return Loc::getMessage('ME_CARFLEET_RESERVATION_NUMBER_EXT') . (int) $matches[1] + 1;
            }
        }
        return Loc::getMessage('ME_CARFLEET_RESERVATION_NUMBER_1');
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function carIsAvailable(int $carId): bool
    {
        $notAvailableCars = $this->reservationIblock->getEntityDataClass()::query()
            ->setSelect(['ID', 'RESERVED_CAR.VALUE', 'NAME'])
            ->where('RESERVED_CAR.VALUE', '=', $carId)
            ->where(
                Query::filter()
                    ->logic('or')
                    ->whereBetween(
                        'ACTIVE_FROM',
                        $this->timeFrom,
                        $this->timeTo
                    )
                    ->whereBetween(
                        'ACTIVE_TO',
                        $this->timeFrom,
                        $this->timeTo
                    )
                    ->where(
                        Query::filter()
                            ->logic('AND')
                            ->where('ACTIVE_FROM', '<=', $this->timeFrom)
                            ->where('ACTIVE_TO', '>=', $this->timeTo)
                    )
            )
            ->setLimit(1)
            ->fetchAll();

        return empty($notAvailableCars);
    }

    /**
     * @return string|null
     * @throws ArgumentException
     * @throws SystemException
     */
    private function getUserPosition(): ?string
    {
        global $USER;
        $userWorkPosition = UserTable::query()
            ->setSelect(['WORK_POSITION'])
            ->where('ID', '=', $USER->getId())
            ->setCacheTtl(3600)
            ->fetch()['WORK_POSITION'];

        return $userWorkPosition ?? null;
    }
}