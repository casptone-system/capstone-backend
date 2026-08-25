<?php

namespace App\Support;

final class RoleSlug
{
    public const SUPERADMIN = 'superadmin';
    public const VPAA = 'vpaa';
    public const QA = 'qa';
    public const DEAN = 'dean';
    public const PROGRAM_CHAIR = 'program-chair';
    public const AREA_IN_CHARGE = 'area-in-charge';
    public const FACULTY = 'faculty';
    public const ACCREDITOR = 'accreditor';

    public const ALL = [
        self::SUPERADMIN,
        self::VPAA,
        self::QA,
        self::DEAN,
        self::PROGRAM_CHAIR,
        self::AREA_IN_CHARGE,
        self::FACULTY,
        self::ACCREDITOR,
    ];

    public const INSTITUTION_WIDE = [
        self::SUPERADMIN,
        self::VPAA,
        self::QA,
        self::ACCREDITOR,
    ];

    /**
     * Legacy / display names that must collapse onto a canonical slug.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'super administrator' => self::SUPERADMIN,
        'super-administrator' => self::SUPERADMIN,
        'superadministrator' => self::SUPERADMIN,
        'super admin' => self::SUPERADMIN,
        'super-admin' => self::SUPERADMIN,
        'superadmin' => self::SUPERADMIN,
        'admin' => self::SUPERADMIN,
        'vpaa' => self::VPAA,
        'vpaa/di' => self::VPAA,
        'vpaa-di' => self::VPAA,
        'vpaadi' => self::VPAA,
        'qa' => self::QA,
        'dean' => self::DEAN,
        'program chair' => self::PROGRAM_CHAIR,
        'program-chair' => self::PROGRAM_CHAIR,
        'programchair' => self::PROGRAM_CHAIR,
        'area in-charge' => self::AREA_IN_CHARGE,
        'area-in-charge' => self::AREA_IN_CHARGE,
        'area incharge' => self::AREA_IN_CHARGE,
        'area-incharge' => self::AREA_IN_CHARGE,
        'areaincharge' => self::AREA_IN_CHARGE,
        'area chair' => self::AREA_IN_CHARGE,
        'area-chair' => self::AREA_IN_CHARGE,
        'faculty' => self::FACULTY,
        'accreditor' => self::ACCREDITOR,
    ];

    public static function canonicalize(?string $role): ?string
    {
        if ($role === null || trim($role) === '') {
            return null;
        }

        $base = strtolower(trim($role));
        $base = str_replace(['_', '/'], ['-', '-'], $base);
        $base = preg_replace('/\s+/', '-', $base) ?: $base;
        $base = preg_replace('/-+/', '-', $base) ?: $base;
        $spaced = str_replace('-', ' ', $base);

        if (isset(self::ALIASES[$base])) {
            return self::ALIASES[$base];
        }

        if (isset(self::ALIASES[$spaced])) {
            return self::ALIASES[$spaced];
        }

        if (str_contains($base, 'vpaa')) {
            return self::VPAA;
        }

        if (str_starts_with($base, 'super')) {
            return self::SUPERADMIN;
        }

        return in_array($base, self::ALL, true) ? $base : null;
    }

    public static function isCanonical(?string $role): bool
    {
        return in_array(self::canonicalize($role), self::ALL, true);
    }

    public static function isInstitutionWide(?string $role): bool
    {
        return in_array(self::canonicalize($role), self::INSTITUTION_WIDE, true);
    }

    /**
     * @return list<string>
     */
    public static function knownAliasesFor(string $canonical): array
    {
        $canonical = self::canonicalize($canonical) ?? $canonical;
        $aliases = [$canonical];

        foreach (self::ALIASES as $alias => $slug) {
            if ($slug === $canonical) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
    }
}
