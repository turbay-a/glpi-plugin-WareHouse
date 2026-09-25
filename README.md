<a id="english"></a>

> **MADE IN UKRAINE FOR THE WORLD.**
> **THERE WAS NO RUSSIAN LANGUAGE AND WILL NOT BE. YOU CAN TRANSLATE IT YOURSELF**
# assetmove — Asset Movements & Write-offs for GLPI 11

<!-- TODO: badges — build · latest release · GLPI version · licence -->

Physical asset movement and write-off workflow for GLPI 11: movement documents with line items, multi-step approval routes, two-phase warehouse transfers, automatic status application, PDF print forms and integration with the Order plugin.

[Українська версія](#ukrainian)

## The problem

GLPI tracks where an asset *is*, not how it got there. There is no movement document, no handover confirmation, no approval trail. Write-offs are done by editing an asset's status by hand. And if you use the Order plugin, assets generated on delivery have no location at all — after a purchase, nobody knows where the hardware physically sits.

`assetmove` adds the missing document layer.

## Features

| Area | What you get |
| --- | --- |
| Movements | Documents with line items, polymorphic source/destination, snapshot of the route, strict status model |
| Two-phase transfers | Warehouse to warehouse with separate *shipped* and *received* confirmations by two different managers |
| Transit | Assets in flight are held in a transit location with a dedicated state — never invisible |
| Discrepancies | Per-line reception: received / missing / damaged, with document-level flagging |
| Write-offs | Separate itemtype and rights, multi-step approval, automatic target-state application |
| Approval routes | Sequential and parallel steps, `all` / `any` modes, optional approvers, value-based step conditions, dynamic approver resolution |
| Printing | PDF print form, auto-attached to the document as a GLPI document |
| Purchasing | Automatic Supplier to Warehouse movement on Order plugin reception |
| Custom assets | GLPI 11 asset definitions supported via a *Movable* capacity |
| Search | Full `rawSearchOptions`: filters, saved searches, CSV/PDF export, dashboards |

## Requirements

| Component | Version |
| --- | --- |
| GLPI | `>= 11.0.0`, `< 11.1` |
| PHP | `>= 8.2` (8.4 recommended) |
| Database | MariaDB 10.11+ / MySQL 8, InnoDB, `utf8mb4` |
| Order plugin | `>= 2.12.0` — optional, purchasing integration only |

## Installation

```bash
cd /var/www/glpi/plugins
tar xjf assetmove-VERSION.tar.bz2
chown -R www-data:www-data assetmove
```

Then go to **Setup → Plugins → assetmove → Install → Enable**.

The release archive ships with `vendor/` included. If you build from source:

```bash
git clone REPO_URL assetmove && cd assetmove
composer install --no-dev --optimize-autoloader
```

### Uninstalling

Uninstalling from the plugin page drops every `glpi_plugin_assetmove_*` table. **Movement and write-off history is lost.** Export what you need first.

## Configuration

Work through these in order after installation.

### 1. Asset states

Create or identify the states you will use in **Setup → Dropdowns → Statuses**: in stock, in transit, in use, written off, lost, damaged. Then map them in **Setup → Plugins → assetmove**.

### 2. Transit location

The plugin creates a `Transit` location on installation. Replace it with your own if you have one, and set it in the configuration. Without a transit location, assets stay booked to the source warehouse while in flight, which makes stocktaking lie.

### 3. Warehouses

**Assets → Movements → Warehouses.** A warehouse is a GLPI location plus a responsible manager and an optional deputy group. The manager is who is allowed to press *Shipped* / *Received* for that warehouse.

Mark one warehouse per entity as **default** — that is where purchases are received.

### 4. Document types

**Assets → Movements → Document types.** The type is not just a label, it is the configuration of the behaviour:

| Setting | Effect |
| --- | --- |
| Source / destination itemtype | Which endpoint pairs are allowed |
| Apply location / user | Whether to write `locations_id` / `users_id` to the asset on execution |
| Target state | Which state the assets get |
| Two-phase | Enables the shipped-then-received flow |
| Approval required | Route the document through approvals before it can be executed |
| Auto-execute on approval | Apply changes automatically once fully approved |
| Return expected | For repair / RMA routes |
| Default for reception | The type used for auto-generated purchase movements |

Suggested starting set: Purchase receipt, Warehouse to Warehouse, Issue to user, Return from user, Send for repair, Write-off: disposal, Write-off: sale, Write-off: loss.

### 5. Approval routes

Sub-tab of a document type. Steps with the same order number run in parallel; different numbers run sequentially.

Approver types: specific user, group, profile, author's manager, source warehouse manager, destination warehouse manager, asset owner.

A step in `any` mode is satisfied by one approval from the group; in `all` mode every mandatory approver must approve. Non-mandatory approvers are informational and do not block.

### 6. Permissions

**Administration → Profiles → your profile → assetmove.** Two independent rightnames:

| Right | Movements | Write-offs |
| --- | --- | --- |
| Read / Create / Update / Delete / Purge | yes | yes |
| Approve | yes | yes |
| Ship | yes | no |
| Receive | yes | no |
| Execute | no | yes |
| Cancel | yes | yes |
| Force | yes | yes |

`Ship` and `Receive` grant the *ability* to press the button. Whether a given user may press it for a given document is decided separately by warehouse responsibility. Both checks must pass.

`Force` bypasses segregation of duties and allows cancelling a shipped document. Grant it to a very small group — every use is logged.

## Usage

### Creating a movement

From **Assets → Movements → Add**, or — more usefully — select assets in any asset list and use the mass action **Create movement**.

Fill in the type, source, destination, responsible person and planned date, then add the assets to the table part. Submit for approval or approve directly, depending on the type.

### Two-phase transfer

1. Source warehouse manager opens the approved document and presses **Shipped**. Assets move to the transit location and the in-transit state.
2. Destination warehouse manager opens it and presses **Receive**, marking each line as received, missing or damaged.
3. Once every line has a reception result, the document closes as **Done**. If anything was missing or damaged, the document is flagged and notifications go out.

The same person cannot do both steps unless the type allows self-reception or the user has `Force`.

### Write-off

Create a write-off document, add the assets, submit for approval. Once all mandatory approvers have approved, the target state is applied to every asset in the table part automatically — no further clicks.

### Printing

The **Print** action generates a PDF of the document and attaches it to the document's *Documents* tab.

<!-- TODO: describe behaviour on regeneration — overwrite the existing attachment or create a new version -->

### Reversing a completed movement

**Done** is terminal. Use **Create reverse movement** — it produces a new document with source and destination swapped and the same line items, linked to the original in both directions.

## Order plugin integration

Enabled by default when the Order plugin is active. Disable it in the plugin configuration if you do not want it.

On reception of an order, the plugin creates one Supplier-to-Warehouse movement per delivery note, containing every received asset as a line.

| Movement field | Source in Order |
| --- | --- |
| Source | Order supplier |
| Destination | Order delivery location, then entity default warehouse, then global default |
| Responsible | Order delivery user, falling back to the warehouse manager |
| Entity | Order line entity |
| Execution date | Line delivery date |
| Initial status | Plugin setting: New (storekeeper confirms) or Done (applied immediately) |

Ignored line types: consumables, cartridges, software licences, contracts, free references and "other" positions.

> **Conflict warning.** Order can set the state of a generated asset itself (*Asset state on generation*). Configure the state **either** there **or** in the `assetmove` document type, never both — otherwise the two plugins overwrite each other's `states_id` and the outcome depends on execution order.

## Notifications and cron

Events: created, approval requested, approved, refused, shipped, received, discrepancy, done, cancelled, overdue.

Cron tasks, found under GLPI **Automatic actions**:

| Task | Purpose |
| --- | --- |
| `overdueShipment` | Shipped documents not received within N days, with escalation at 2N |
| `overduePlanned` | Planned date passed, document not completed |
| `overdueReturn` | Items sent to a supplier and not returned by the expected date |
| `pendingValidation` | Approvals sitting untouched for N days |

## API

Both itemtypes are exposed through the GLPI REST API. Use the fully-qualified, URL-encoded itemtype:

```text
GET /apirest.php/GlpiPlugin%5CAssetmove%5CMovement
GET /apirest.php/GlpiPlugin%5CAssetmove%5CWriteoff
```

Status transitions performed through the API go through the same state machine and permission checks as the UI.

## Troubleshooting

**Purchases do not create movements.** Check that Order is active, that the integration is enabled in the configuration, that a document type is marked *default for reception*, and that the entity has a default warehouse.

**Assets end up in the wrong state after a purchase.** See the conflict warning above — the state is being set twice.

**A warehouse manager cannot press Shipped.** Two separate checks: the `Ship` right in the profile, and being the manager or a deputy group member of the source warehouse. Both must be satisfied.

**Custom assets do not appear in the asset picker.** Enable the *Movable* capacity on the asset definition in **Setup → Asset definitions**.

**Cyrillic characters are broken in the PDF.**

<!-- TODO: font configuration notes -->

## Known limitations

- Entity-to-entity movement records the fact only and does not perform a GLPI `Transfer`.
- Consumables, cartridges, software licences and contracts are not supported as movable items.
- No electronic signature on printed forms.

## Development

```bash
composer install
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

Contributions welcome. Please keep code and commit messages in English, and UI strings in gettext.

## Licence

GPL-3.0-or-later.

---

<a id="ukrainian"></a>

> **ЗРОБЛЕНО В УКРАЇНІ ДЛЯ СВІТУ.**
> **РОСІЙСЬКОЇ МОВИ НЕ БУЛО І НЕ БУДЕ. МОЖЕТЕ ПЕРЕКЛАСТИ САМОСТІЙНО**
# assetmove — облік переміщень і списання активів для GLPI 11

Документообіг фізичних переміщень і списання активів для GLPI 11: документи з табличною частиною, багатокрокові маршрути погодження, двофазні переміщення між складами, автоматичне проставляння статусів, друковані форми PDF та інтеграція з плагіном Order.

[English version](#english)

## Проблема

GLPI знає, де актив *зараз*, але не знає, як він туди потрапив. Немає документа переміщення, немає підтвердження передачі, немає сліду погодження. Списання робиться ручною зміною статусу. А якщо використовується плагін Order, згенеровані при оприбуткуванні активи взагалі не мають локації, і після закупівлі ніхто не знає, де техніка фізично.

`assetmove` додає той шар документів, якого бракує.

## Можливості

| Напрям | Що дає |
| --- | --- |
| Переміщення | Документи з табличною частиною, поліморфні джерело та призначення, знімок маршруту, жорстка статусна модель |
| Двофазні переміщення | Склад до складу з окремими підтвердженнями «відправлено» і «отримано» від двох різних МВО |
| Транзит | Активи в дорозі числяться в транзитній локації з окремим станом і ніколи не зникають з обліку |
| Розбіжності | Порядкове приймання: прийнято / недостача / пошкоджено, з позначкою на документі |
| Списання | Окремий itemtype і права, багатокрокове погодження, автоматичне застосування цільового стану |
| Маршрути погодження | Послідовні й паралельні кроки, режими «всі» / «будь-хто», необов'язкові погоджувачі, умови за сумою, динамічні погоджувачі |
| Друк | Друкована форма PDF, що автоматично прикріплюється до документа |
| Закупівлі | Автоматичне переміщення «Постачальник → Склад» при прийманні в Order |
| Кастомні активи | Підтримка asset definitions GLPI 11 через capacity «Переміщуваний» |
| Пошук | Повні `rawSearchOptions`: фільтри, збережені пошуки, експорт CSV/PDF, дашборди |

## Вимоги

| Компонент | Версія |
| --- | --- |
| GLPI | `>= 11.0.0`, `< 11.1` |
| PHP | `>= 8.2`, рекомендовано 8.4 |
| БД | MariaDB 10.11+ / MySQL 8, InnoDB, `utf8mb4` |
| Плагін Order | `>= 2.12.0` — опційно, лише для інтеграції з закупівлями |

## Встановлення

```bash
cd /var/www/glpi/plugins
tar xjf assetmove-VERSION.tar.bz2
chown -R www-data:www-data assetmove
```

Далі **Налаштування → Плагіни → assetmove → Встановити → Активувати**.

Реліз-архів містить `vendor/`. Якщо збираєте з вихідників:

```bash
git clone REPO_URL assetmove && cd assetmove
composer install --no-dev --optimize-autoloader
```

### Видалення

Деінсталяція зі сторінки плагінів видаляє всі таблиці `glpi_plugin_assetmove_*`. **Історія переміщень і списань втрачається.** Спочатку вивантажте потрібне.

## Налаштування

Пройдіть по порядку після встановлення.

### 1. Стани активів

Створіть або визначте потрібні стани в **Налаштування → Списки → Статуси**: на складі, в дорозі, в експлуатації, списано, втрачено, пошкоджено. Потім змапте їх у **Налаштування → Плагіни → assetmove**.

### 2. Транзитна локація

Плагін створює локацію `Транзит` при інсталяції. Замініть на власну, якщо така є, і вкажіть у конфігурації. Без транзитної локації активи в дорозі лишаються записаними на склад-джерело, і інвентаризація бреше.

### 3. Склади

**Активи → Переміщення → Склади.** Склад — це локація GLPI плюс відповідальна особа (МВО) і необов'язкова група заступників. МВО — це той, кому дозволено тиснути «Відправлено» і «Отримано» для цього складу.

Один склад на ентіті позначте як **дефолтний** — саме на нього приймаються закупівлі.

### 4. Типи документів

**Активи → Переміщення → Типи документів.** Тип — не просто підпис, а конфігурація поведінки:

| Налаштування | Що робить |
| --- | --- |
| Тип джерела та призначення | Які пари кінцівок дозволені |
| Застосовувати локацію / користувача | Чи писати `locations_id` та `users_id` в актив при виконанні |
| Цільовий стан | У який стан переводяться активи |
| Двофазність | Вмикає потік «відправлено» → «отримано» |
| Потрібне погодження | Документ проходить маршрут перед виконанням |
| Автовиконання після погодження | Застосовувати зміни автоматично після повного погодження |
| Очікується повернення | Для ремонту та RMA |
| Тип за замовчуванням для приймання | Використовується для автостворених документів із закупівель |

Рекомендований стартовий набір: Оприбуткування, Склад до складу, Видача користувачу, Повернення від користувача, Відправка в ремонт, Списання: утилізація, Списання: продаж, Списання: втрата.

### 5. Маршрути погодження

Підвкладка типу документа. Кроки з однаковим номером виконуються паралельно, з різними — послідовно.

Типи погоджувачів: конкретний користувач, група, профіль, керівник автора, МВО складу-джерела, МВО складу-призначення, власник активу.

Крок у режимі «будь-хто» закривається одним погодженням із групи; у режимі «всі» мають погодити всі обов'язкові учасники. Необов'язкові погоджувачі інформаційні й не блокують.

### 6. Права

**Адміністрування → Профілі → ваш профіль → assetmove.** Два незалежні набори:

| Право | Переміщення | Списання |
| --- | --- | --- |
| Читання / Створення / Зміна / Видалення / Очищення | так | так |
| Погодити | так | так |
| Відправити | так | ні |
| Прийняти | так | ні |
| Виконати | ні | так |
| Скасувати | так | так |
| Примусово | так | так |

«Відправити» і «Прийняти» дають *можливість* натиснути кнопку. Чи має право конкретний користувач зробити це для конкретного документа — вирішується окремо за відповідальністю за склад. Обидві перевірки мають пройти.

«Примусово» обходить розділення обов'язків і дозволяє скасувати відправлений документ. Видавайте дуже вузькому колу — кожне використання логується.

## Робота з плагіном

### Створення переміщення

З **Активи → Переміщення → Додати**, або, що зручніше, виділити активи в будь-якому списку і викликати масову дію **Створити переміщення**.

Заповніть тип, джерело, призначення, відповідального і планову дату, додайте активи в табличну частину. Далі подайте на погодження або погодьте одразу, залежно від типу.

### Двофазне переміщення

1. МВО складу-джерела відкриває погоджений документ і тисне **Відправлено**. Активи переходять у транзитну локацію і стан «в дорозі».
2. МВО складу-призначення відкриває документ і тисне **Прийняти**, позначаючи кожен рядок як прийнятий, недостачу або пошкоджений.
3. Коли по всіх рядках є результат приймання, документ закривається як **Виконано**. Якщо щось не доїхало або пошкоджене, документ позначається і йдуть сповіщення.

Одна людина не може зробити обидва кроки, якщо тип не дозволяє самоприймання або користувач не має права «Примусово».

### Списання

Створіть документ списання, додайте активи, подайте на погодження. Після погодження всіма обов'язковими учасниками цільовий стан застосовується до всіх активів табличної частини автоматично, без додаткових натискань.

### Друк

Дія **Друк** генерує PDF документа і прикріплює його на вкладку «Документи».

<!-- TODO: описати поведінку при повторній генерації — перезапис чи нова версія -->

### Відкат виконаного переміщення

**Виконано** — термінальний статус. Використовуйте **Створити зворотне переміщення**: створюється новий документ із поміняними місцями джерелом і призначенням та тією ж табличною частиною, зв'язаний з оригіналом в обидві сторони.

## Інтеграція з плагіном Order

Вмикається автоматично, якщо Order активний. Вимикається в налаштуваннях плагіна.

При прийманні замовлення створюється одне переміщення «Постачальник → Склад» на кожну накладну, з усіма прийнятими активами як рядками.

| Поле документа | Джерело в Order |
| --- | --- |
| Джерело | Постачальник замовлення |
| Призначення | Локація доставки замовлення, потім дефолтний склад ентіті, потім глобальний дефолт |
| Відповідальний | Користувач доставки замовлення, фолбек — МВО складу |
| Ентіті | Ентіті рядка замовлення |
| Дата виконання | Дата поставки рядка |
| Початковий статус | Налаштування плагіна: «Новий», який підтверджує комірник, або «Виконано» з негайним застосуванням |

Ігноруються: витратні матеріали, картриджі, ліцензії, контракти, вільні позиції та «інше».

> **Попередження про конфлікт.** Order уміє сам ставити стан згенерованому активу (*Стан активу при генерації*). Налаштовуйте стан **або** там, **або** в типі документа `assetmove`, але не в обох місцях — інакше плагіни перезаписують один одного, і результат залежить від порядку виконання.

## Сповіщення і cron

Події: створено, запит на погодження, погоджено, відхилено, відправлено, отримано, розбіжність, виконано, скасовано, прострочено.

Cron-задачі в розділі **Автоматичні дії** GLPI:

| Задача | Призначення |
| --- | --- |
| `overdueShipment` | Відправлені документи, не прийняті протягом N днів, з ескалацією на 2N |
| `overduePlanned` | Планова дата минула, документ не завершено |
| `overdueReturn` | Позиції, відправлені постачальнику й не повернуті до очікуваної дати |
| `pendingValidation` | Погодження, що висять без відповіді N днів |

## API

Обидва itemtype доступні через REST API GLPI. Використовуйте повне ім'я класу в URL-енкодингу:

```text
GET /apirest.php/GlpiPlugin%5CAssetmove%5CMovement
GET /apirest.php/GlpiPlugin%5CAssetmove%5CWriteoff
```

Зміни статусів через API проходять ту саму стейт-машину і ті самі перевірки прав, що й в інтерфейсі.

## Усунення проблем

**Закупівлі не створюють переміщень.** Перевірте, що Order активний, інтеграція увімкнена в конфігурації, є тип документа з ознакою «за замовчуванням для приймання» і в ентіті є дефолтний склад.

**Активи після закупівлі отримують не той стан.** Див. попередження про конфлікт вище — стан ставиться двічі.

**МВО не може натиснути «Відправлено».** Дві окремі перевірки: право «Відправити» в профілі і статус МВО або членство в групі заступників складу-джерела. Мають пройти обидві.

**Кастомні активи не з'являються у виборі.** Увімкніть capacity «Переміщуваний» на визначенні активу в **Налаштування → Визначення активів**.

**Кирилиця в PDF ламається.**

<!-- TODO: нотатки про налаштування шрифтів -->

## Відомі обмеження

- Переміщення між організаціями фіксує лише факт і не виконує `Transfer` GLPI.
- Витратні матеріали, картриджі, ліцензії та контракти не підтримуються як переміщувані позиції.
- Електронний підпис друкованих форм не реалізований.

## Розробка

```bash
composer install
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

Внески вітаються. Код і повідомлення коммітів англійською, рядки інтерфейсу через gettext.

## Ліцензія

GPL-3.0-or-later.
