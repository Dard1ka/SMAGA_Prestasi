# W1-B — domain services (app/Domain) + unit tests  ·  status: done

## Files (owned by me)
- `app/Domain/Support/{Decimal,Dates,Key}.php` — bcmath helpers, strict Y-m-d → CarbonImmutable, enum-or-string keys
- `app/Domain/Points/{PointCalculator,PointBreakdown,WeightTable,PointFormatter}.php`
- `app/Domain/Deadlines/ReportDeadline.php`
- `app/Domain/Archive/{GraduationCalculator,GraduationDates,SchoolYear}.php`
- `app/Domain/Accounts/{InitialPassword,PasswordPolicy}.php`
- `app/Domain/Letters/LetterNumber.php`
- `app/Domain/Registrations/{RegistrationStateMachine,InvalidTransition}.php`
- `app/Domain/Leaderboard/{Ranker,RankedStudent}.php`
- `app/Domain/Files/UploadRules.php`
- `tests/Unit/Domain/**` (13 files) incl. `EnumContractTest.php` (domain maps == App\Enums)

## Contracts other areas can use
All classes are `final`, stateless or configured by constructor. Every key argument takes an App\Enums
case OR its string value. Dates accept `DateTimeInterface|'Y-m-d'|'Y-m-d H:i:s'`, return `CarbonImmutable` (midnight).
- `PointCalculator::compute(string|int|float $wLevel, $wResult, $wOrganizer): string` → "68.00" (half-up, 2 dp)
- `PointCalculator::breakdown(WeightTable, Level|string, ResultType|string, WeightCategory|string): PointBreakdown`
  → `->toArray()` = `['w_level','w_result','w_organizer','points']` (achievements columns)
- `WeightTable::defaults()` · `::fromRows(iterable $weightValues)` (array/model/object with group,key,value)
  · `->level($k)` `->result($k)` `->organizer($k)`: string · `->levels()/results()/organizers()` · `->toRows()` (seed weight_values)
- `PointFormatter::format($points, int $maxDecimals = 1): string` "33,6" · "80" · "1.234,5"; `formatWeight($w, 2)` "0,85"
- `new ReportDeadline(int $days = 45)` → `dueDate($finalOn)`, `daysLeft($finalOn, $today): int` (0 on due date, <0 late), `isOverdue($finalOn, $today): bool`, `days()`
- `new GraduationCalculator(int $archiveMonths = 18)` → `calculate(int $level, SchoolYear|string $year): GraduationDates{grade12StartDate, archiveAt}`,
  `grade12StartDate(...)`, `archiveAt(...)`, `archiveAtFrom($grade12Start)`, `isDue($archiveAt, $today): bool`
- `SchoolYear::fromString('2026/2027')` (throws Indonesian msg) · `::isValid(string)` · `::containing($date)` (year starts 1 July) · `->startYear`, `endYear()`, `toString()`
- `InitialPassword::make($birthDate): string` (import/admin reset only) · `matches(string $candidate, $birthDate): bool` (K7 detection only)
- `new PasswordPolicy(int $minLength = 8)` → `validate(string $pw, $birthDate = null, ?string $nis = null, ?string $nisn = null, ?string $name = null): list<string>` · `passes(...): bool`.
  Messages are `PasswordPolicy::MSG_*` + "Minimal N karakter."; checks normalised (lowercase, separators removed).
- `LetterNumber::format(string $template, int|string $seq, $issuedOn): string` (`{seq}` `{roman_month}` `{year}`),
  consts `TEMPLATE_SISWA`, `TEMPLATE_GURU`; `romanMonth(int)`, `parseSequence(int|string): int` (1–99999, msg `MSG_INVALID_SEQUENCE`),
  `isValidSequence(mixed)`, `assertValidTemplate(string)`, `pattern(string $template)` (regex for FormRequests), `parse($template, $number): ?array{sequence,month,year}`
- `RegistrationStateMachine`: `transitions()`, `statuses()`, `allowedNext($from)`, `canTransition($from, $to): bool`,
  `assertTransition($from, $to)` throws `InvalidTransition extends DomainException` (user-safe message, `->from`, `->to`),
  `phaseFor($status): int` (1–6, same as `RegistrationStatus::phase()`), `isFinal($status)`. Constants `SM::MENUNGGU_KEPSEK` etc.
- `new Ranker(array $tiers = Ranker::DEFAULT_RESULT_TIERS)` → `rank(iterable<['student_id','name','points','results'=>list,'alumni'=>bool]>): list<RankedStudent>`.
  Rows with the same student_id are summed. `RankedStudent{rank, position, studentId, name, points, tierCounts, achievements, alumni}`.
- `new UploadRules(int $maxBytes = 10 MB, array $extensions = [pdf,jpg,jpeg,png,webp])` → `validate(string $path, string $originalName): list<string>`,
  `sniffMime($path): ?string` (finfo), `hasPdfMagic($path)`, `extensionForMime($mime)`, `isAllowedExtension/Mime`, `extensions()`, `mimeTypes()`,
  `maxBytes()`, `maxKilobytes()` (for `max:`), `storagePath($mime, ?$now, $dir = 'lampiran')` → `lampiran/2026/10/<ulid>.jpg`, `sanitizeOriginalName($name)` (≤ 150 chars)

## Commands verified
- `docker compose run --rm --no-deps app ./vendor/bin/pest tests/Unit` → 227 passed (488 assertions)
- `docker compose run --rm --no-deps app ./vendor/bin/pint app/Domain tests/Unit` → pass, 35 files

## Assumptions / deviations from the spec
- **menunggu_kepsek → dibatalkan is allowed.** §6 row `dibatalkan` says "leader cancels before the principal decides",
  and mockup notes D.md:18 show "Batalkan pendaftaran" on "Menunggu Kepala Sekolah"; the §6 "next" column omits it.
- **PasswordPolicy min length defaults to 8** (task brief); SECURITY K5 says `min(10)` for students → pass `new PasswordPolicy(10)` if K5 wins.
  HIBP `uncompromised()` stays in the Laravel rule layer. Name check = tokens ≥ 4 letters; NIS/NISN ≥ 4 digits.
- Ranker: `rank` is shared when points AND result tiers are equal (1, 2, 2, 4); name only orders them (`position`).
  Semifinalis and penghargaan khusus share a tier (both 0,30), same as `ResultType::tier()`.
- Report due date is inclusive (overdue from the next day). Settings range guards: deadline 1–365 days, archive 1–120 months.
- Letter sequence 1–99999 for both types (SECURITY §3g regex allows only 4 digits for siswa); "0312" → 312.
- UploadRules uses the global 10 MB of ARCHITECTURE §4; SECURITY §3d per-purpose limits = pass a smaller `maxBytes`/extension list.
  Stored extension comes from the sniffed MIME; a mismatch between allowed types (png named .jpg) is accepted.

## Requests for other owners
- @user: confirm the two deviations above (cancel while waiting for Kepsek; min 8 vs 10) and update ARCHITECTURE §6 / SECURITY K5.
- @W1-A: enums duplicate default weights/tiers/phases (`defaultWeight()`, `tier()`, `phase()`, `isFinal()`); `EnumContractTest` fails if they drift.
  Weight seeder can use `WeightTable::defaults()->toRows()`.
- @wave 2 (providers owner): bind `ReportDeadline`/`GraduationCalculator` from settings `report_deadline_days` / `archive_months_after_grade12`.

## Follow-ups (not done)
- PDF active-content scan (`/JavaScript`, `/OpenAction`, …) and image re-encode belong in `App\Domain\Files\StoreUpload` (wave 2).
