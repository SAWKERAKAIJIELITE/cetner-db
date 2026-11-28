<?php

namespace App\Modules\Employees\Database\Seeders;

use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Employees\Actions\UpdateFieldDefinition;
use App\Modules\Employees\Models\FieldDefinition;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\FieldDefinitions;
use Illuminate\Database\Seeder;

/**
 * Every environment: «دليل الحقول» as field_definitions (D-032). A field gets the workbook's cycle and ✔ when
 * it is first created; afterwards those two are the settings managers'. Labels, sections, types and the
 * identity flag follow the catalogue on every run. Re-run safe. The 13 attachments follow the fields, of type
 * `document` (D-049): a document counts as filled when the employee has a current approved file of that kind.
 */
class FieldDefinitionsSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        $created = false;

        foreach (FieldCatalogue::FIELDS as $key => $field) {
            $definition = FieldDefinition::query()->find($key) ?? new FieldDefinition;
            $isNew = ! $definition->exists;

            $definition->forceFill([
                'key' => $key,
                'label_ar' => $field['label'],
                'section_ar' => FieldCatalogue::SECTIONS[$field['section']],
                'workbook_column' => $field['column'],
                'type' => $field['type'],
                'list_key' => $field['list'] ?? null,
                'identity' => $field['identity'],
                'sort_order' => ++$order,
            ]);

            if ($isNew) {
                $definition->forceFill(['cycle' => $field['cycle'], 'counts_for_completeness' => $field['counts']]);
                $created = true;
            }

            $definition->save();
        }

        foreach (DocumentCatalogue::FILE_KINDS as $key => $kind) {
            $definition = FieldDefinition::query()->find($key) ?? new FieldDefinition;
            $isNew = ! $definition->exists;

            $definition->forceFill([
                'key' => $key,
                'label_ar' => $kind['label'],
                'section_ar' => DocumentCatalogue::SECTION,
                'workbook_column' => $kind['column'],
                'type' => 'document',
                'list_key' => null,
                'identity' => false,
                'sort_order' => ++$order,
            ]);

            if ($isNew) {
                $definition->forceFill(['cycle' => $kind['cycle'], 'counts_for_completeness' => $kind['counts']]);
                $created = true;
            }

            $definition->save();
        }

        FieldDefinitions::forget();

        if ($created) {
            UpdateFieldDefinition::recomputeAll();
        }
    }
}
