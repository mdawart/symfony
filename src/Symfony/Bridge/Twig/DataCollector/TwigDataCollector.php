<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Twig\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Markup;
use Twig\Profiler\Dumper\HtmlDumper;
use Twig\Profiler\Profile;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @final
 */
class TwigDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private Profile $profile;
    private ?Environment $twig;
    private array $computed;

    public function __construct(Profile $profile, Environment $twig = null)
    {
        $this->profile = $profile;
        $this->twig = $twig;
    }

    public function collect(Request $request, Response $response, \Throwable $exception = null): void
    {
    }

    public function reset(): void
    {
        $this->profile->reset();
        unset($this->computed);
        $this->data = [];
    }

    public function lateCollect(): void
    {
        $this->data['profile'] = serialize($this->profile);
        $this->data['template_paths'] = [];

        if (null === $this->twig) {
            return;
        }

        $templateFinder = function (Profile $profile) use (&$templateFinder) {
            if ($profile->isTemplate()) {
                try {
                    $template = $this->twig->load($name = $profile->getName());
                } catch (LoaderError) {
                    $template = null;
                }

                if (null !== $template && '' !== $path = $template->getSourceContext()->getPath()) {
                    $this->data['template_paths'][$name] = $path;
                }
            }

            foreach ($profile as $p) {
                $templateFinder($p);
            }
        };
        $templateFinder($this->profile);
    }

    public function getTime(): int
    {
        return $this->getProfile()->getDuration() * 1000;
    }

    public function getTemplateCount(): int
    {
        return $this->getComputedData('template_count');
    }

    public function getTemplatePaths(): array
    {
        return $this->data['template_paths'];
    }

    public function getTemplates(): array
    {
        return $this->getComputedData('templates');
    }

    public function getBlockCount(): int
    {
        return $this->getComputedData('block_count');
    }

    public function getMacroCount(): int
    {
        return $this->getComputedData('macro_count');
    }

    public function getHtmlCallGraph(): Markup
    {
        $dumper = new HtmlDumper();
        $dump = $dumper->dump($this->getProfile());

        // needed to remove the hardcoded CSS styles
        $dump = str_replace([
            '<span style="background-color: #ffd">',
            '<span style="color: #d44">',
            '<span style="background-color: #dfd">',
            '<span style="background-color: #ddf">',
        ], [
            '<span class="status-warning">',
            '<span class="status-error">',
            '<span class="status-success">',
            '<span class="status-info">',
        ], $dump);

        return new Markup($dump, 'UTF-8');
    }

    public function getProfile(): Profile
    {
        return $this->profile ??= unserialize($this->data['profile'], ['allowed_classes' => ['Twig_Profiler_Profile', Profile::class]]);
    }

    private function getComputedData(string $index)
    {
        $this->computed ??= $this->computeData($this->getProfile());

        return $this->computed[$index];
    }

    private function computeData(Profile $profile): array
    {
        $data = [
            'template_count' => 0,
            'block_count' => 0,
            'macro_count' => 0,
        ];

        $templates = [];
        foreach ($profile as $p) {
            $d = $this->computeData($p);

            $data['template_count'] += ($p->isTemplate() ? 1 : 0) + $d['template_count'];
            $data['block_count'] += ($p->isBlock() ? 1 : 0) + $d['block_count'];
            $data['macro_count'] += ($p->isMacro() ? 1 : 0) + $d['macro_count'];

            if ($p->isTemplate()) {
                if (!isset($templates[$p->getTemplate()])) {
                    $templates[$p->getTemplate()] = 1;
                } else {
                    ++$templates[$p->getTemplate()];
                }
            }

            foreach ($d['templates'] as $template => $count) {
                if (!isset($templates[$template])) {
                    $templates[$template] = $count;
                } else {
                    $templates[$template] += $count;
                }
            }
        }
        $data['templates'] = $templates;

        return $data;
    }

    public function getName(): string
    {
        return 'twig';
    }
}
