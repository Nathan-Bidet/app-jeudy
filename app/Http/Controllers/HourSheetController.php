<?php

namespace App\Http\Controllers;

use App\Models\HourSheet;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Hours\ApprovedLeaveDayService;
use App\Support\Access\AccessManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Exception\InvalidSheetNameException;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HourSheetController extends Controller
{
    public function __construct(
        private readonly ApprovedLeaveDayService $approvedLeaveDayService
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        $hourSheets = HourSheet::query()
            ->where('user_id', $userId)
            ->orderByDesc('work_date')
            ->get()
            ->map(fn (HourSheet $hourSheet): array => [
                'id' => (int) $hourSheet->id,
                'work_date' => $hourSheet->work_date?->toDateString(),
                'morning_start' => $hourSheet->morning_start,
                'morning_end' => $hourSheet->morning_end,
                'afternoon_start' => $hourSheet->afternoon_start,
                'afternoon_end' => $hourSheet->afternoon_end,
                'total_minutes' => (int) $hourSheet->total_minutes,
                'has_breakfast_before_5' => (bool) $hourSheet->has_breakfast_before_5,
                'has_lunch' => (bool) $hourSheet->has_lunch,
                'has_dinner_after_21' => (bool) $hourSheet->has_dinner_after_21,
                'has_long_night' => (bool) $hourSheet->has_long_night,
            ])
            ->values()
            ->all();

        $canCreate = app(AccessManager::class)->can($request->user(), 'heures.create');
        $canExport = app(AccessManager::class)->can($request->user(), 'heures.export');
        $approvedLeaveDays = $this->approvedLeaveDayService->approvedLeaveMapForUser(
            $userId,
            config('hours.min_visible_date', '2026-04-27'),
            now(config('app.timezone', 'Europe/Paris'))->toDateString()
        );

        return Inertia::render('Hours/Index', [
            'hourSheets' => $hourSheets,
            'canCreate' => $canCreate,
            'canExport' => $canExport,
            'approvedLeaveDays' => $approvedLeaveDays,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'morning_start' => ['nullable', 'date_format:H:i'],
            'morning_end' => ['nullable', 'date_format:H:i'],
            'afternoon_start' => ['nullable', 'date_format:H:i'],
            'afternoon_end' => ['nullable', 'date_format:H:i'],
            'has_breakfast_before_5' => ['nullable', 'boolean'],
            'has_lunch' => ['nullable', 'boolean'],
            'has_dinner_after_21' => ['nullable', 'boolean'],
            'has_long_night' => ['nullable', 'boolean'],
        ]);

        $morningMinutes = $this->computeRangeMinutes(
            $validated['morning_start'] ?? null,
            $validated['morning_end'] ?? null,
            'matin'
        );
        $afternoonMinutes = $this->computeRangeMinutes(
            $validated['afternoon_start'] ?? null,
            $validated['afternoon_end'] ?? null,
            'soir'
        );

        HourSheet::query()->updateOrCreate(
            [
                'user_id' => (int) $request->user()->id,
                'work_date' => $validated['work_date'],
            ],
            [
                'morning_start' => $validated['morning_start'] ?? null,
                'morning_end' => $validated['morning_end'] ?? null,
                'afternoon_start' => $validated['afternoon_start'] ?? null,
                'afternoon_end' => $validated['afternoon_end'] ?? null,
                'total_minutes' => $morningMinutes + $afternoonMinutes,
                'has_breakfast_before_5' => (bool) ($validated['has_breakfast_before_5'] ?? false),
                'has_lunch' => (bool) ($validated['has_lunch'] ?? false),
                'has_dinner_after_21' => (bool) ($validated['has_dinner_after_21'] ?? false),
                'has_long_night' => (bool) ($validated['has_long_night'] ?? false),
            ]
        );

        return redirect()->route('hours.index')->with('success', 'Journée enregistrée avec succès.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $hourSheets = HourSheet::query()
            ->with('user:id,name,first_name,last_name')
            ->whereBetween('work_date', [$validated['start_date'], $validated['end_date']])
            ->orderBy('user_id')
            ->orderBy('work_date')
            ->get();

        $userIds = $hourSheets->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $leaveUserIds = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where(function ($query) use ($validated): void {
                $query
                    ->whereBetween('start_at', [$validated['start_date'].' 00:00:00', $validated['end_date'].' 23:59:59'])
                    ->orWhere(function ($subQuery) use ($validated): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->whereBetween('end_at', [$validated['start_date'].' 00:00:00', $validated['end_date'].' 23:59:59']);
                    })
                    ->orWhere(function ($subQuery) use ($validated): void {
                        $subQuery
                            ->whereNotNull('end_at')
                            ->where('start_at', '<=', $validated['start_date'].' 00:00:00')
                            ->where('end_at', '>=', $validated['end_date'].' 23:59:59');
                    });
            })
            ->pluck('target_user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $userIds = array_values(array_unique(array_merge($userIds, $leaveUserIds)));

        $approvedLeavesByUser = $this->approvedLeaveDayService->approvedLeaveMapForUsers(
            $userIds,
            $validated['start_date'],
            $validated['end_date']
        );

        $downloadName = sprintf(
            'heures_%s_%s.xlsx',
            date('Y-m-d', strtotime($validated['start_date'])),
            date('Y-m-d', strtotime($validated['end_date']))
        );

        $filePath = storage_path('app/temp/'.$downloadName);
        if (! is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        $writer = new Writer();
        $writer->openToFile($filePath);

        $groupedByUser = $hourSheets->groupBy(fn (HourSheet $sheet): int => (int) $sheet->user_id);
        $usersById = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'first_name', 'last_name'])
            ->keyBy('id');
        $exportUserIds = array_values(array_unique(array_merge(
            array_keys($groupedByUser->all()),
            array_keys($approvedLeavesByUser)
        )));
        sort($exportUserIds);
        $usedTitles = [];

        foreach ($exportUserIds as $index => $exportUserId) {
            $rows = $groupedByUser->get($exportUserId, collect());
            $sheetRef = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $user = $usersById->get((int) $exportUserId);
            $title = $this->uniqueSheetTitle($user, $usedTitles);
            $usedTitles[] = $title;

            try {
                $sheetRef->setName($title);
            } catch (InvalidSheetNameException) {
                $sheetRef->setName('Utilisateur '.($index + 1));
            }

            $writer->addRow(Row::fromValues([
                'Date',
                'Jour',
                'Début matin',
                'Fin matin',
                'Début soir',
                'Fin soir',
                'Total heures travaillées',
                'Casse-croûte (Avant 5h)',
                'Déjeuner',
                'Dîner (Après 21h)',
                'Nuit (Déplacement long)',
            ]));

            $userId = (int) $exportUserId;
            $rowsByDate = [];
            foreach ($rows as $sheet) {
                $dateKey = $sheet->work_date?->toDateString();
                if ($dateKey) {
                    $rowsByDate[$dateKey] = $sheet;
                }
            }

            $leaveMap = $approvedLeavesByUser[$userId] ?? [];
            $allDates = array_unique(array_merge(array_keys($rowsByDate), array_keys($leaveMap)));
            sort($allDates);

            foreach ($allDates as $dateKey) {
                if (isset($rowsByDate[$dateKey])) {
                    $sheet = $rowsByDate[$dateKey];
                    $date = $sheet->work_date;
                    $writer->addRow(Row::fromValues([
                        $date?->format('d/m/Y'),
                        $date?->locale('fr')->translatedFormat('l') ? ucfirst((string) $date->locale('fr')->translatedFormat('l')) : '',
                        $this->formatTimeForExport($sheet->morning_start),
                        $this->formatTimeForExport($sheet->morning_end),
                        $this->formatTimeForExport($sheet->afternoon_start),
                        $this->formatTimeForExport($sheet->afternoon_end),
                        $this->formatMinutesForExport((int) $sheet->total_minutes),
                        $sheet->has_breakfast_before_5 ? 'Oui' : 'Non',
                        $sheet->has_lunch ? 'Oui' : 'Non',
                        $sheet->has_dinner_after_21 ? 'Oui' : 'Non',
                        $sheet->has_long_night ? 'Oui' : 'Non',
                    ]));
                    continue;
                }

                if (! isset($leaveMap[$dateKey])) {
                    continue;
                }

                $leaveDate = CarbonImmutable::parse($dateKey);
                $writer->addRow(Row::fromValues([
                    $leaveDate->format('d/m/Y'),
                    ucfirst((string) $leaveDate->locale('fr')->translatedFormat('l')),
                    'Congé validé',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ]));
            }
        }

        if ($exportUserIds === []) {
            $writer->getCurrentSheet()->setName('Heures');
            $writer->addRow(Row::fromValues([
                'Date',
                'Jour',
                'Début matin',
                'Fin matin',
                'Début soir',
                'Fin soir',
                'Total heures travaillées',
                'Casse-croûte (Avant 5h)',
                'Déjeuner',
                'Dîner (Après 21h)',
                'Nuit (Déplacement long)',
            ]));
        }

        $writer->close();

        return response()->download($filePath, $downloadName)->deleteFileAfterSend(true);
    }

    private function computeRangeMinutes(?string $start, ?string $end, string $label): int
    {
        if (! $start || ! $end) {
            return 0;
        }

        $startMinutes = $this->timeToMinutes($start);
        $endMinutes = $this->timeToMinutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            return 0;
        }

        if ($endMinutes < $startMinutes) {
            throw ValidationException::withMessages([
                'work_date' => sprintf('Plage %s invalide: arrivée avant départ.', $label),
            ]);
        }

        return $endMinutes - $startMinutes;
    }

    private function timeToMinutes(string $time): ?int
    {
        if (! str_contains($time, ':')) {
            return null;
        }

        [$hour, $minute] = explode(':', $time);
        if (! is_numeric($hour) || ! is_numeric($minute)) {
            return null;
        }

        return ((int) $hour * 60) + (int) $minute;
    }

    private function formatTimeForExport(?string $value): string
    {
        if (! $value || ! str_contains($value, ':')) {
            return '';
        }

        [$hours, $minutes] = explode(':', $value);

        return sprintf('%02d:%02d', (int) $hours, (int) $minutes);
    }

    private function formatMinutesForExport(int $totalMinutes): string
    {
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        return sprintf('%02dh%02d', $hours, $minutes);
    }

    private function uniqueSheetTitle(?object $user, array $usedTitles): string
    {
        $fullName = trim((string) ($user?->last_name ? ($user->last_name.' ') : '').(string) ($user?->first_name ?? ''));
        if ($fullName === '') {
            $fullName = (string) ($user?->name ?? 'Utilisateur');
        }

        $baseTitle = $this->sanitizeSheetTitle($fullName);
        if (! in_array($baseTitle, $usedTitles, true)) {
            return $baseTitle;
        }

        $suffix = 2;
        while (true) {
            $candidate = $this->sanitizeSheetTitle($baseTitle.' '.$suffix);
            if (! in_array($candidate, $usedTitles, true)) {
                return $candidate;
            }
            $suffix++;
        }
    }

    private function sanitizeSheetTitle(string $title): string
    {
        $clean = trim(str_replace(['/', '\\', '?', '*', '[', ']'], ' ', $title));
        $clean = preg_replace('/\s+/', ' ', $clean) ?: 'Utilisateur';

        if (mb_strlen($clean) <= 31) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, 31));
    }
}
