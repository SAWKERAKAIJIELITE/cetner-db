<?php

namespace App\Modules\Documents\Support;

/**
 * The workbook's 13 attachments («الوثائق والمرفقات»: twelve «رابط …» columns and «صورة حساب الشام كاش»), keyed as
 * the frontend keys them, and the conduct record's document (D-049). The design's label first, the workbook's
 * field, its column, its update cycle and whether it counts for completeness (the workbook's ✔; afterwards the
 * field guide's, editable). One current file of each kind per employee.
 */
final class DocumentCatalogue
{
    /** The conduct record's document: on a record of «سجل السلوك الوظيفي», not on the file. */
    public const CONDUCT = 'conduct_document';

    /** @var array<string, array{label: string, workbook: string, column: string, cycle: string, counts: bool}> */
    public const FILE_KINDS = [
        'identity_copy' => ['label' => 'صورة الهوية', 'workbook' => 'رابط الهوية', 'column' => 'AL', 'cycle' => 'once', 'counts' => true],
        'contract_terms' => ['label' => 'وثيقة أحكام المتعاقد', 'workbook' => 'رابط وثيقة أحكام المتعاقد', 'column' => 'AM', 'cycle' => 'once', 'counts' => false],
        'security_form' => ['label' => 'الإستمارة الأمنية', 'workbook' => 'رابط الاستمارة الأمنية', 'column' => 'AN', 'cycle' => 'once', 'counts' => true],
        'signature_form' => ['label' => 'نموذج التوقيع', 'workbook' => 'رابط صورة التوقيع', 'column' => 'AO', 'cycle' => 'once', 'counts' => false],
        'course_certificates' => ['label' => 'شهادات الدورات', 'workbook' => 'رابط الدورات', 'column' => 'EB', 'cycle' => 'semi_annual', 'counts' => false],
        'portrait' => ['label' => 'الصورة الشخصية', 'workbook' => 'رابط الصورة الشخصية', 'column' => 'EI', 'cycle' => 'annual', 'counts' => true],
        'family_statement' => ['label' => 'البيان العائلي', 'workbook' => 'رابط البيان العائلي', 'column' => 'EJ', 'cycle' => 'annual', 'counts' => true],
        'practical_certificate' => ['label' => 'الشهادة العملية', 'workbook' => 'رابط الشهادة', 'column' => 'EK', 'cycle' => 'annual', 'counts' => true],
        'graduation_certificate' => ['label' => 'مصدقة التخرج', 'workbook' => 'رابط مصدقة التخرج', 'column' => 'EL', 'cycle' => 'annual', 'counts' => false],
        'contract_copy' => ['label' => 'صورة العقد الوظيفي', 'workbook' => 'رابط صورة العقد', 'column' => 'EM', 'cycle' => 'annual', 'counts' => false],
        'cv' => ['label' => 'السيرة الذاتية', 'workbook' => 'رابط السيرة الذاتية', 'column' => 'EN', 'cycle' => 'annual', 'counts' => false],
        'inquiry_record' => ['label' => 'نشرة الإستعلامات', 'workbook' => 'رابط نشرة الاستعلامات', 'column' => 'EO', 'cycle' => 'annual', 'counts' => false],
        'sham_cash_copy' => ['label' => 'صورة حساب شام كاش', 'workbook' => 'صورة حساب الشام كاش', 'column' => 'EP', 'cycle' => 'annual', 'counts' => false],
    ];

    /** The section of the field guide they are in. */
    public const SECTION = 'الوثائق والمرفقات';

    /** What a file may be, by its content (not its name): PDF and the common images. */
    public const MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return [...array_keys(self::FILE_KINDS), self::CONDUCT];
    }

    public static function isFileKind(string $kind): bool
    {
        return isset(self::FILE_KINDS[$kind]);
    }

    public static function label(string $kind): string
    {
        return $kind === self::CONDUCT ? 'وثيقة القيد السلوكي' : self::FILE_KINDS[$kind]['label'];
    }

    /** The most a file may weigh, in kilobytes (the design: 5 MB). */
    public static function maxKilobytes(): int
    {
        return (int) config('hr.documents_max_kb', 5120);
    }
}
