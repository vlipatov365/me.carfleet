# Компонент me.carfleet

## Задача:

В компании предусмотрена возможность выбора служебного автомобиля для служебной поездки на определенное время из не занятых другими сотрудниками. В служебной части корпоративного сайта необходимо будет размещать актуальную информацию о доступных для конкретного сотрудника автомобилях на запланированное время поездки.
Дополнительные условия:
- каждая модель автомобиля имеет определенную категорию комфорта (первая, вторая, третья... );
- для определенной должности сотрудников доступны только автомобили определенной категории комфорта (одной или нескольких категорий);
- за каждым автомобилем закреплён свой водитель.


## Структура хранения контента:

### Инфоблок Автопарк:

Инфоблок с поддержкой древовидной структуры.

- **CODE** - carfleet
- **API_CODE** - carfleet
- **NAME** - Автопарк

В инфоблоке обязательно хранение элементов в разделе, то есть
нельзя привязывать элемент к корневому разделу. Каждый раздел
является категорией с пользовательским полем UF_AVAILABLE_FOR.
В нём указываются должности, для которых доступна категория.

Элементы:

- **NAME** - любое название, я использовал марку и модель
- Свойство **STATE_NUMBER** - обязательное, не множественное, строка. Госномер автомобиля.
- Свойство **DRIVER** - необязательно, не множественное, привязка к пользователю. Водитель, привязанный к автомобилю.

### Инфоблок Бронирование:

Инфоблок не требующий древовидной структуры.

- **CODE** - reservation_car
- **API_CODE** - reservation
- **NAME** - Бронирование машин

В инфоблоке хранятся все бронирования. Интервал бронирования записывается в поля ACTIVE_FROM и
ACTIVE_TO.

- **NAME** - название соответствует регулярному выражению '/Бронирование\s+#(\d+)/', где в конце добавляются следующий номер бронирования по порядку.
- Свойство **RESERVED_CAR** - обязательное, не множественное, привязку к элементу инфоблока _carfleet_.

Для правильного сохранения названия бронирования в компонент добавлен метод, который получает последнее бронивароние, вычисляет его номер
и реализует номер следующий.

```php
/**
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
```
Неправильно хранить такой метод в компоненте. Задача требовала только компонент, поэтому он добавлен сюда. 
Но правильнее было бы реализовать модуль, который бы регистрировал событие на создание/обновление элемента инфоблока и проверял(изменял)
название элемента в соответствии.

## Работа компонента:

В компоненте не реализованы параметры, поскольку в задаче не было указано их наличие.
Вся работа строится на получении Get-параметров __TIME_FROM__(время начала бронирования), __TIME_TO__(время окончания бронирования),
__CAR_ID__(Id элемента в инфоблоке __carfleet__). Если указан __TIME_FROM__ и(или) __TIME_TO__, список автомобилей будет отфильтрован
по этим датам. Если указан параметр __CAR_ID__, то будет проведена проверка на доступность автомобиля в указанные даты и при успехе, автомобиль
будет зарезервирован.