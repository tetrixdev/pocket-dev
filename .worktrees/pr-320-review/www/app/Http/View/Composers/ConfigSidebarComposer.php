<?php

namespace App\Http\View\Composers;

use Illuminate\View\View;

class ConfigSidebarComposer
{
    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        // Only load sidebar data for config pages
        $view->with([
            'skills' => $this->getSkills(),
        ]);
    }

    /**
     * Get list of skills for sidebar
     */
    protected function getSkills(): array
    {
        $path = '/home/appuser/.claude/skills';

        if (!is_dir($path)) {
            return [];
        }

        $skills = [];
        $files = scandir($path);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($path . '/' . $file)) {
                $skills[] = [
                    'name' => $file,
                ];
            }
        }

        return $skills;
    }
}
