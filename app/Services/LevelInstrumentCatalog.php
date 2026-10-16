<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\LevelParameterRow;
use App\Models\ParameterContentRow;
use App\Support\AreaParameterCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LevelInstrumentCatalog
{
    public static function ensureSeeded(string $level): void
    {
        if (! in_array($level, AccreditationCycle::LEVELS, true)) {
            return;
        }

        if (LevelParameterRow::query()->where('level', $level)->exists()) {
            return;
        }

        DB::transaction(function () use ($level) {
            if (LevelParameterRow::query()->where('level', $level)->exists()) {
                return;
            }

            $now = now();
            $rows = [];

            foreach (AccreditationArea::AACCUP_AREAS as $area) {
                foreach (AreaParameterCatalog::parameters()[$area['code']] ?? [] as $definition) {
                    foreach (AreaParameterCatalog::rowsFor($area['code'], $definition['code']) as $index => $content) {
                        $rows[] = [
                            'level' => $level,
                            'area_code' => $area['code'],
                            'parameter_code' => $definition['code'],
                            'content' => $content,
                            'sort_order' => $index,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                LevelParameterRow::query()->insert($chunk);
            }
        });
    }

    /**
     * Copy this level's parameter text onto every program area at that level.
     */
    public static function publish(string $level, string $areaCode, string $parameterCode): void
    {
        $master = self::rowsFor($level, $areaCode, $parameterCode);

        $parameters = AccreditationParameter::query()
            ->where('code', $parameterCode)
            ->whereHas('area', function ($query) use ($areaCode, $level) {
                $query->where('code', $areaCode)
                    ->whereHas('cycle', fn ($cycle) => $cycle->where('level', $level));
            })
            ->with(['area', 'contentRows' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->get();

        $progress = app(AreaProgressService::class);

        foreach ($parameters as $parameter) {
            self::align($parameter, $master);
            $progress->refresh($parameter->area);
        }
    }

    /**
     * Official text for a program area, when this level has been seeded.
     *
     * @return list<string>|null
     */
    public static function contentsForArea(AccreditationArea $area, string $parameterCode): ?array
    {
        $area->loadMissing('cycle');
        $level = $area->cycle?->level;

        if (! is_string($level) || $level === '') {
            return null;
        }

        if (! LevelParameterRow::query()->where('level', $level)->exists()) {
            return null;
        }

        return self::rowsFor($level, (string) $area->code, $parameterCode)
            ->pluck('content')
            ->all();
    }

    /**
     * @return Collection<int, LevelParameterRow>
     */
    public static function rowsFor(string $level, string $areaCode, string $parameterCode): Collection
    {
        return LevelParameterRow::query()
            ->where('level', $level)
            ->where('area_code', $areaCode)
            ->where('parameter_code', $parameterCode)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, LevelParameterRow>  $master
     */
    private static function align(AccreditationParameter $parameter, Collection $master): void
    {
        $local = $parameter->contentRows->values();
        $used = [];

        foreach ($master as $index => $masterRow) {
            $match = $local->first(function (ParameterContentRow $row) use ($used, $masterRow) {
                return ! isset($used[$row->id]) && $row->content === $masterRow->content;
            });

            if (! $match) {
                $match = $local->first(function (ParameterContentRow $row) use ($used, $index) {
                    return ! isset($used[$row->id]) && (int) $row->sort_order === $index;
                });
            }

            if ($match) {
                $used[$match->id] = true;
                if ($match->content !== $masterRow->content || (int) $match->sort_order !== $index) {
                    $match->update([
                        'content' => $masterRow->content,
                        'sort_order' => $index,
                    ]);
                }

                continue;
            }

            ParameterContentRow::create([
                'parameter_id' => $parameter->id,
                'content' => $masterRow->content,
                'sort_order' => $index,
            ]);
        }

        foreach ($local as $row) {
            if (isset($used[$row->id])) {
                continue;
            }

            $row->loadCount(['documents', 'comments']);
            if ($row->documents_count > 0 || $row->comments_count > 0) {
                continue;
            }

            $row->delete();
        }
    }
}
