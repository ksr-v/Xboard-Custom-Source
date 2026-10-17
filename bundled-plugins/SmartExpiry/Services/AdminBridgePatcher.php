<?php

namespace Plugin\SmartExpiry\Services;

use RuntimeException;

final class AdminBridgePatcher
{
    public const MARKER = 'smart-expiry-v7';
    public const V6_MARKER = 'smart-expiry-v6';
    public const V5_MARKER = 'smart-expiry-v5';
    public const V4_MARKER = 'smart-expiry-v4';
    public const V3_MARKER = 'smart-expiry-v3';
    public const V2_MARKER = 'smart-expiry-v2';
    public const LEGACY_MARKER = 'smart-expiry-v1';

    private const LOCALE_FILES = [
        'public/assets/admin/locales/en-US.js',
        'public/assets/admin/locales/ru-RU.js',
        'public/assets/admin/locales/zh-CN.js',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    public function apply(): string
    {
        return $this->withLock(fn (): string => $this->applyUnlocked());
    }

    private function applyUnlocked(): string
    {
        [$paths, $state] = $this->validateFiles();
        if ($state === 'v7') {
            return 'already patched';
        }

        $patched = [];
        foreach ($paths as $relative => $path) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw $this->unsupported();
            }
            if ($relative === 'public/assets/admin/index.html') {
                if (in_array($state, ['source', 'v1', 'v2'], true)) {
                    $content = $this->patchV3Content($relative, $content);
                }
                if (in_array($state, ['source', 'v1', 'v2', 'v3'], true)) {
                    $content = $this->patchV4Content($relative, $content);
                }
                if (in_array($state, ['source', 'v1', 'v2', 'v3', 'v4'], true)) {
                    $content = $this->patchV5Content($relative, $content);
                }
                if (in_array($state, ['source', 'v1', 'v2', 'v3', 'v4', 'v5'], true)) {
                    $content = $this->patchV6Content($relative, $content);
                }
                $patched[$relative] = $this->patchV7Content($relative, $content);
                continue;
            }
            if ($state === 'source') {
                $content = $this->patchV1Content($relative, $content);
            }
            if ($state === 'source' || $state === 'v1') {
                $content = $this->patchV2Content($relative, $content);
            }
            if ($state === 'source' || $state === 'v1' || $state === 'v2') {
                $content = $this->patchV3Content($relative, $content);
            }
            if (in_array($state, ['source', 'v1', 'v2', 'v3'], true)) {
                $content = $this->patchV4Content($relative, $content);
            }
            if (in_array($state, ['source', 'v1', 'v2', 'v3', 'v4'], true)) {
                $content = $this->patchV5Content($relative, $content);
            }
            if (in_array($state, ['source', 'v1', 'v2', 'v3', 'v4', 'v5'], true)) {
                $content = $this->patchV6Content($relative, $content);
            }
            $patched[$relative] = $this->patchV7Content($relative, $content);
        }

        $this->writeAllOrRestore($paths, $patched, 'v7');

        return $state === 'source' ? 'patched' : 'upgraded from ' . $state;
    }

    public function status(): string
    {
        return $this->withLock(function (): string {
            [, $state] = $this->validateFiles();
            return $state;
        });
    }

    public function remove(): string
    {
        return $this->withLock(function (): string {
            [$paths, $state] = $this->validateFiles();
            if ($state === 'source') {
                return 'not patched';
            }
            if ($state !== 'v7') {
                throw new RuntimeException('SmartExpiry cleanup requires a complete current patch. No changes were applied.');
            }

            $cleaned = [];
            foreach ($paths as $relative => $path) {
                $content = file_get_contents($path);
                if ($content === false) {
                    throw $this->unsupported();
                }
                $cleaned[$relative] = $this->removeV7Content($relative, $content);
            }
            $this->writeAllOrRestore($paths, $cleaned, 'source');

            [, $after] = $this->validateFiles();
            if ($after !== 'source') {
                throw new RuntimeException('SmartExpiry cleanup verification failed.');
            }
            return 'removed';
        });
    }

    private function withLock(callable $operation): mixed
    {
        $lockPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'framework' . DIRECTORY_SEPARATOR . 'xboard-admin-patch.lock';
        $directory = dirname($lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('SmartExpiry could not create the shared admin patch lock directory.');
        }
        $handle = fopen($lockPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('SmartExpiry could not acquire the shared admin patch lock.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{array<string, string>, string} */
    private function validateFiles(): array
    {
        $paths = [];
        $contents = [];

        $adminIndex = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.html';
        $indexContent = is_file($adminIndex) ? file_get_contents($adminIndex) : false;
        if ($indexContent === false
            || preg_match_all('~(?:^|[./])assets/(index-[A-Za-z0-9_-]+\.js)(?:[?"\']|$)~', $indexContent, $matches) < 1
        ) {
            throw $this->unsupported();
        }
        $bundleNames = array_values(array_unique($matches[1]));
        if (count($bundleNames) !== 1) {
            throw new RuntimeException('SmartExpiry could not identify one unique admin entry bundle. No changes were applied.');
        }

        $bridgeFiles = array_merge(
            ['public/assets/admin/assets/' . $bundleNames[0]],
            self::LOCALE_FILES,
        );
        $files = array_merge(['public/assets/admin/index.html'], $bridgeFiles);
        foreach ($files as $relative) {
            $path = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)) {
                throw $this->unsupported();
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw $this->unsupported();
            }
            $paths[$relative] = $path;
            $contents[$relative] = $content;
        }

        $bridgeContents = array_intersect_key($contents, array_flip($bridgeFiles));
        $fileCount = count($bridgeContents);
        $v7Count = 0;
        $v6Count = 0;
        $v5Count = 0;
        $v4Count = 0;
        $v3Count = 0;
        $v2Count = 0;
        foreach ($bridgeContents as $relative => $content) {
            $v7Count += $this->hasV7Marker($relative, $content) ? 1 : 0;
            $v6Count += $this->hasV6Marker($relative, $content) ? 1 : 0;
            $v5Count += $this->hasV5Marker($relative, $content) ? 1 : 0;
            $v4Count += $this->hasV4Marker($relative, $content) ? 1 : 0;
            $v3Count += $this->hasV3Marker($relative, $content) ? 1 : 0;
            $v2Count += $this->hasV2Marker($relative, $content) ? 1 : 0;
        }
        $v1Count = count(array_filter($bridgeContents, static fn (string $content): bool => str_contains($content, self::LEGACY_MARKER)));
        if ($v7Count === $fileCount && $v6Count === 0 && $v5Count === 0 && $v4Count === 0 && $v3Count === 0 && $v2Count === 0 && $v1Count === 0) {
            if (!str_contains($indexContent, '?v=' . self::MARKER)) {
                throw new RuntimeException('SmartExpiry detected a partially patched admin build. No changes were applied.');
            }
            return [$paths, 'v7'];
        }
        if ($v6Count === $fileCount && $v7Count === 0 && $v5Count === 0 && $v4Count === 0 && $v3Count === 0 && $v2Count === 0 && $v1Count === 0) {
            return [$paths, 'v6'];
        }
        if ($v5Count === $fileCount && $v7Count === 0 && $v6Count === 0 && $v4Count === 0 && $v3Count === 0 && $v2Count === 0 && $v1Count === 0) {
            return [$paths, 'v5'];
        }
        if ($v4Count === $fileCount && $v7Count === 0 && $v6Count === 0 && $v5Count === 0 && $v3Count === 0 && $v2Count === 0 && $v1Count === 0) {
            return [$paths, 'v4'];
        }
        if ($v3Count === $fileCount && $v7Count === 0 && $v6Count === 0 && $v5Count === 0 && $v4Count === 0 && $v2Count === 0 && $v1Count === 0) {
            return [$paths, 'v3'];
        }
        if ($v2Count === $fileCount && $v7Count === 0 && $v6Count === 0 && $v5Count === 0 && $v4Count === 0 && $v3Count === 0 && $v1Count === 0) {
            return [$paths, 'v2'];
        }
        if ($v1Count === $fileCount && $v7Count === 0 && $v6Count === 0 && $v5Count === 0 && $v4Count === 0 && $v2Count === 0 && $v3Count === 0) {
            return [$paths, 'v1'];
        }
        if ($v1Count === 0 && $v2Count === 0 && $v3Count === 0 && $v4Count === 0 && $v5Count === 0 && $v6Count === 0 && $v7Count === 0) {
            return [$paths, 'source'];
        }
        throw new RuntimeException('SmartExpiry detected a partially patched admin build. No changes were applied.');
    }

    private function patchV1Content(string $relative, string $content, bool $reverse = false): string
    {
        if ($this->isAdminBundle($relative)) {
            $functionAnchor = 'u=Nv({resolver:Mv(q8t)});return H.useEffect';
            $functionReplacement = 'u=Nv({resolver:Mv(q8t)}),h=e=>{const t=new Date,n=Number(u.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,r=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(r,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),i.setHours(23,59,59,999),u.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),o(!1)};/*smart-expiry-v1*/return H.useEffect';

            $buttonsAnchor = 'Q.jsxs("div",{className:"flex gap-2",children:[Q.jsx(Lf,{type:"button",variant:"outline",className:"flex-1",onClick:()=>{t.onChange(null),o(!1)},children:e("edit.form.expire_time_permanent")}),Q.jsx(Lf,{type:"button",variant:"outline",className:"flex-1",onClick:()=>{const e=new Date;e.setMonth(e.getMonth()+1),e.setHours(23,59,59,999),t.onChange(Math.floor(e.getTime()/1e3)),o(!1)},children:e("edit.form.expire_time_1month")}),Q.jsx(Lf,{type:"button",variant:"outline",className:"flex-1",onClick:()=>{const e=new Date;e.setMonth(e.getMonth()+3),e.setHours(23,59,59,999),t.onChange(Math.floor(e.getTime()/1e3)),o(!1)},children:e("edit.form.expire_time_3months")})]})';
            $button = static fn (int $months, string $key): string => 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>h(' . $months . '),children:e("edit.form.' . $key . '")})';
            $buttonsReplacement = 'Q.jsxs("div",{className:"flex flex-wrap gap-2",children:['
                . 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>{t.onChange(null),o(!1)},children:e("edit.form.expire_time_permanent")}),'
                . $button(1, 'expire_time_1month') . ','
                . $button(3, 'expire_time_3months') . ','
                . $button(6, 'expire_time_6months') . ','
                . $button(9, 'expire_time_9months') . ','
                . $button(12, 'expire_time_1year') . ']})';

            if ($reverse) {
                [$functionAnchor, $functionReplacement] = [$functionReplacement, $functionAnchor];
                [$buttonsAnchor, $buttonsReplacement] = [$buttonsReplacement, $buttonsAnchor];
            }

            $content = $this->replaceExactlyOnce($content, $functionAnchor, $functionReplacement, 'renewal function');
            return $this->replaceExactlyOnce($content, $buttonsAnchor, $buttonsReplacement, 'expiry shortcut buttons');
        }

        $translations = match ($relative) {
            'public/assets/admin/locales/en-US.js' => ['Six Months', 'Nine Months', 'One Year'],
            'public/assets/admin/locales/ru-RU.js' => ['Шесть месяцев', 'Девять месяцев', 'Один год'],
            'public/assets/admin/locales/zh-CN.js' => ['六个月', '九个月', '一年'],
            default => throw $this->unsupported(),
        };
        $anchor = '"expire_time_3months": ';
        $position = strpos($content, $anchor);
        if ($position === false || strpos($content, $anchor, $position + 1) !== false) {
            throw $this->unsupported();
        }
        $lineEnd = strpos($content, "\n", $position);
        if ($lineEnd === false) {
            throw $this->unsupported();
        }
        $indent = '        ';
        $addition = "\n{$indent}\"expire_time_6months\": \"{$translations[0]}\",";
        $addition .= "\n{$indent}\"expire_time_9months\": \"{$translations[1]}\",";
        $addition .= "\n{$indent}\"expire_time_1year\": \"{$translations[2]}\",";
        $addition .= "\n{$indent}\"smart_expiry_marker\": \"" . self::V2_MARKER . "\",";

        return substr($content, 0, $lineEnd) . $addition . substr($content, $lineEnd);
    }

    private function patchV2Content(string $relative, string $content, bool $reverse = false): string
    {
        if ($this->isAdminBundle($relative)) {
            $editAnchor = 'h=e=>{const t=new Date,n=Number(u.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,r=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(r,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),i.setHours(23,59,59,999),u.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),o(!1)};/*smart-expiry-v1*/';
            $editReplacement = 'h=e=>{const t=new Date,n=Number(u.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,r=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(r,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),u.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),o(!1)};/*smart-expiry-edit-v2*/';
            if ($reverse) {
                [$editAnchor, $editReplacement] = [$editReplacement, $editAnchor];
            }
            $content = $this->replaceExactlyOnce($content, $editAnchor, $editReplacement, 'edit-user renewal function');

            $createFunctionAnchor = '[s,o]=H.useState([]),[a,l]=H.useState(!1);H.useEffect';
            $createFunctionReplacement = '[s,o]=H.useState([]),[a,l]=H.useState(!1),[d,u]=H.useState(!1),h=e=>{const t=new Date,n=Number(r.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,s=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(s,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),r.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),u(!1)};/*smart-expiry-create-v2*/H.useEffect';
            if ($reverse) {
                [$createFunctionAnchor, $createFunctionReplacement] = [$createFunctionReplacement, $createFunctionAnchor];
            }
            $content = $this->replaceExactlyOnce($content, $createFunctionAnchor, $createFunctionReplacement, 'create-user renewal function');

            $createPickerAnchor = 'Q.jsxs(P$t,{children:[Q.jsx(j$t,{asChild:!0,children:Q.jsx(Yy,{children:Q.jsxs(Lf,{variant:"outline",className:Im("h-9 w-full px-3 text-left font-mono text-xs font-normal",!r.watch("expired_at")&&"text-muted-foreground"),children:[r.watch("expired_at")?SS(r.watch("expired_at")):Q.jsx("span",{children:t("generate.form.expire_time_placeholder")}),Q.jsx(vat,{className:"ml-auto h-3.5 w-3.5 opacity-50"})]})})}),Q.jsxs(B$t,{className:"flex w-auto flex-col space-y-2 p-2",children:[Q.jsx(R$t,{asChild:!0,children:Q.jsx(Lf,{variant:"outline",className:"h-8 w-full font-mono text-xs",onClick:()=>{r.setValue("expired_at",null)},children:t("generate.form.permanent")})}),Q.jsx("div",{className:"rounded-md border",children:Q.jsx(o$t,{mode:"single",selected:r.watch("expired_at")?new Date(1e3*r.watch("expired_at")):void 0,onSelect:e=>{e&&r.setValue("expired_at",e?.getTime()/1e3)}})})]})]})';
            $button = static fn (int $months, string $key): string => 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>h(' . $months . '),children:t("generate.form.' . $key . '")})';
            $createPickerReplacement = 'Q.jsxs(P$t,{open:d,onOpenChange:u,children:[Q.jsx(j$t,{asChild:!0,children:Q.jsx(Yy,{children:Q.jsxs(Lf,{type:"button",variant:"outline",className:Im("h-9 w-full px-3 text-left font-mono text-xs font-normal",!r.watch("expired_at")&&"text-muted-foreground"),onClick:()=>u(!0),children:[r.watch("expired_at")?SS(r.watch("expired_at")):Q.jsx("span",{children:t("generate.form.expire_time_placeholder")}),Q.jsx(vat,{className:"ml-auto h-3.5 w-3.5 opacity-50"})]})})}),Q.jsx(B$t,{className:"w-auto p-0",align:"start",side:"top",sideOffset:4,onInteractOutside:e=>{e.preventDefault()},onEscapeKeyDown:e=>{e.preventDefault()},children:Q.jsxs("div",{className:"flex flex-col space-y-3 p-3",children:[Q.jsxs("div",{className:"flex flex-wrap gap-2",children:['
                . 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>{r.setValue("expired_at",null,{shouldDirty:!0,shouldValidate:!0}),u(!1)},children:t("generate.form.permanent")}),'
                . $button(1, 'expire_time_1month') . ','
                . $button(3, 'expire_time_3months') . ','
                . $button(6, 'expire_time_6months') . ','
                . $button(9, 'expire_time_9months') . ','
                . $button(12, 'expire_time_1year')
                . ']}),Q.jsx("div",{className:"rounded-md border",children:Q.jsx(o$t,{mode:"single",selected:r.watch("expired_at")?new Date(1e3*r.watch("expired_at")):void 0,onSelect:e=>{if(e){const t=new Date(r.getValues("expired_at")?1e3*r.getValues("expired_at"):Date.now());e.setHours(t.getHours(),t.getMinutes(),t.getSeconds()),r.setValue("expired_at",Math.floor(e.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0})}},disabled:e=>e<new Date,initialFocus:!0,className:"rounded-md border-none"})}),Q.jsxs("div",{className:"space-y-1.5",children:[Q.jsxs("div",{className:"flex items-center justify-between",children:[Q.jsx("div",{className:"text-sm font-medium text-muted-foreground",children:t("generate.form.expire_time_specific")}),Q.jsx(Lf,{type:"button",variant:"ghost",size:"sm",onClick:()=>{const e=new Date;e.setHours(23,59,59,999),r.setValue("expired_at",Math.floor(e.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0})},className:"h-6 px-2 text-xs",children:t("generate.form.expire_time_today")})]}),Q.jsxs("div",{className:"flex gap-2",children:[Q.jsx(u8e,{type:"datetime-local",step:"1",value:SS(r.watch("expired_at"),"yyyy-MM-dd\'T\'HH:mm:ss"),onChange:e=>{const t=new Date(e.target.value);isNaN(t.getTime())||r.setValue("expired_at",Math.floor(t.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0})},className:"flex-1"}),Q.jsx(Lf,{type:"button",variant:"outline",onClick:()=>u(!1),children:t("generate.form.expire_time_confirm")})]})]})]})})]})';

            if ($reverse) {
                [$createPickerAnchor, $createPickerReplacement] = [$createPickerReplacement, $createPickerAnchor];
            }

            return $this->replaceExactlyOnce($content, $createPickerAnchor, $createPickerReplacement, 'create-user expiry picker');
        }

        $translations = match ($relative) {
            'public/assets/admin/locales/en-US.js' => ['Permanent', 'One Month', 'Three Months', 'Six Months', 'Nine Months', 'One Year', 'Specific Time', 'Set to end of today', 'Confirm'],
            'public/assets/admin/locales/ru-RU.js' => ['Навсегда', 'Один месяц', 'Три месяца', 'Шесть месяцев', 'Девять месяцев', 'Один год', 'Конкретное время', 'До конца сегодня', 'Подтвердить'],
            'public/assets/admin/locales/zh-CN.js' => ['永久', '一个月', '三个月', '六个月', '九个月', '一年', '具体时间', '设为当天结束', '确定'],
            default => throw $this->unsupported(),
        };
        $permanentAnchor = '        "permanent": ';
        $position = false;
        $offset = 0;
        while (($candidate = strpos($content, $permanentAnchor, $offset)) !== false) {
            $candidateEnd = strpos($content, "\n", $candidate);
            if ($candidateEnd !== false && str_starts_with(substr($content, $candidateEnd), "\n        \"subscription\":")) {
                if ($position !== false) {
                    throw $this->unsupported();
                }
                $position = $candidate;
            }
            $offset = $candidate + strlen($permanentAnchor);
        }
        if ($position === false) {
            throw $this->unsupported();
        }
        $lineEnd = strpos($content, "\n", $position);
        if ($lineEnd === false) {
            throw $this->unsupported();
        }
        $replacement = $permanentAnchor . '"' . $translations[0] . '",';
        $replacement .= "\n        \"expire_time_1month\": \"{$translations[1]}\",";
        $replacement .= "\n        \"expire_time_3months\": \"{$translations[2]}\",";
        $replacement .= "\n        \"expire_time_6months\": \"{$translations[3]}\",";
        $replacement .= "\n        \"expire_time_9months\": \"{$translations[4]}\",";
        $replacement .= "\n        \"expire_time_1year\": \"{$translations[5]}\",";
        $replacement .= "\n        \"expire_time_specific\": \"{$translations[6]}\",";
        $replacement .= "\n        \"expire_time_today\": \"{$translations[7]}\",";
        $replacement .= "\n        \"expire_time_confirm\": \"{$translations[8]}\",";
        $replacement .= "\n        \"smart_expiry_marker_v2\": \"" . self::V2_MARKER . "\",";
        $content = substr($content, 0, $position) . $replacement . substr($content, $lineEnd);

        return str_replace('"smart_expiry_marker": "' . self::LEGACY_MARKER . '"', '"smart_expiry_marker": "' . self::V2_MARKER . '"', $content);
    }

    private function patchV3Content(string $relative, string $content): string
    {
        if ($relative === 'public/assets/admin/index.html') {
            $pattern = '~(\./(?:locales/(?:en-US|ru-RU|zh-CN)\.js|assets/index-[A-Za-z0-9_-]+\.js))(?:\?v=smart-expiry-v\d+)?~';
            $patched = preg_replace($pattern, '$1?v=' . self::V3_MARKER, $content, -1, $count);
            if ($patched === null || $count !== 4) {
                throw new RuntimeException('SmartExpiry could not update the admin asset cache keys. No changes were applied.');
            }

            return $patched;
        }

        if (!$this->isAdminBundle($relative)) {
            if (!str_contains($content, self::V2_MARKER)) {
                throw $this->unsupported();
            }

            return str_replace(self::V2_MARKER, self::V3_MARKER, $content);
        }

        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-edit-v2*/',
            '/*smart-expiry-edit-v3*/',
            'edit-user v3 marker',
        );
        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-create-v2*/',
            '/*smart-expiry-create-v3*/',
            'create-user v3 marker',
        );

        $editContainer = 'Q.jsxs("div",{className:"flex flex-wrap gap-2",children:[Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>{t.onChange(null),o(!1)}';
        $createContainer = 'Q.jsxs("div",{className:"flex flex-wrap gap-2",children:[Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>{r.setValue("expired_at",null,{shouldDirty:!0,shouldValidate:!0}),u(!1)}';
        $content = $this->replaceExactlyOnce(
            $content,
            $editContainer,
            str_replace('className:"flex flex-wrap gap-2"', 'className:"grid grid-cols-3 gap-2"', $editContainer),
            'edit-user expiry button container',
        );
        $content = $this->replaceExactlyOnce(
            $content,
            $createContainer,
            str_replace('className:"flex flex-wrap gap-2"', 'className:"grid grid-cols-3 gap-2"', $createContainer),
            'create-user expiry button container',
        );

        $buttonClass = 'className:"min-w-[5rem] flex-1"';
        if (substr_count($content, $buttonClass) !== 12) {
            throw new RuntimeException('SmartExpiry could not find the twelve expiry buttons. No changes were applied.');
        }
        $content = str_replace(
            $buttonClass,
            'className:"w-full min-w-0 px-1 text-xs sm:px-3 sm:text-sm"',
            $content,
        );

        $fallbacks = [
            ['e', 'edit.form.expire_time_6months', 'edit.form.expire_time_1month', '六个月', 'Шесть месяцев', 'Six Months'],
            ['e', 'edit.form.expire_time_9months', 'edit.form.expire_time_1month', '九个月', 'Девять месяцев', 'Nine Months'],
            ['e', 'edit.form.expire_time_1year', 'edit.form.expire_time_1month', '一年', 'Один год', 'One Year'],
            ['t', 'generate.form.expire_time_1month', 'generate.form.permanent', '一个月', 'Один месяц', 'One Month'],
            ['t', 'generate.form.expire_time_3months', 'generate.form.permanent', '三个月', 'Три месяца', 'Three Months'],
            ['t', 'generate.form.expire_time_6months', 'generate.form.permanent', '六个月', 'Шесть месяцев', 'Six Months'],
            ['t', 'generate.form.expire_time_9months', 'generate.form.permanent', '九个月', 'Девять месяцев', 'Nine Months'],
            ['t', 'generate.form.expire_time_1year', 'generate.form.permanent', '一年', 'Один год', 'One Year'],
        ];
        foreach ($fallbacks as [$translator, $key, $probe, $zh, $ru, $en]) {
            $search = $translator . '("' . $key . '")';
            $default = $translator . '("' . $probe . '").includes("月")?"' . $zh
                . '":/[А-Яа-яЁё]/.test(' . $translator . '("' . $probe . '"))?"' . $ru . '":"' . $en . '"';
            $replacement = $translator . '("' . $key . '",{defaultValue:' . $default . '})';
            $content = $this->replaceExactlyOnce($content, $search, $replacement, $key . ' fallback');
        }

        return $content;
    }

    private function patchV4Content(string $relative, string $content): string
    {
        if ($relative === 'public/assets/admin/index.html') {
            $pattern = '~(\./(?:locales/(?:en-US|ru-RU|zh-CN)\.js|assets/index-[A-Za-z0-9_-]+\.js))(?:\?v=smart-expiry-v\d+)?~';
            $patched = preg_replace($pattern, '$1?v=' . self::V4_MARKER, $content, -1, $count);
            if ($patched === null || $count !== 4) {
                throw new RuntimeException('SmartExpiry could not update the admin asset cache keys. No changes were applied.');
            }

            return $patched;
        }

        if (!$this->isAdminBundle($relative)) {
            if (!str_contains($content, self::V3_MARKER)) {
                throw $this->unsupported();
            }

            return str_replace(self::V3_MARKER, self::V4_MARKER, $content);
        }

        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-edit-v3*/',
            '/*smart-expiry-edit-v4*/',
            'edit-user v4 marker',
        );
        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-create-v3*/',
            '/*smart-expiry-create-v4*/',
            'create-user v4 marker',
        );

        $fallbacks = [
            ['e', 'edit.form.expire_time_6months', 'edit.form.expire_time_1month', '月', '六个月', 'Шесть месяцев', 'Six Months', true],
            ['e', 'edit.form.expire_time_9months', 'edit.form.expire_time_1month', '月', '九个月', 'Девять месяцев', 'Nine Months', true],
            ['e', 'edit.form.expire_time_1year', 'edit.form.expire_time_1month', '月', '一年', 'Один год', 'One Year', true],
            ['t', 'generate.form.expire_time_1month', 'generate.form.permanent', '永久', '一个月', 'Один месяц', 'One Month', true],
            ['t', 'generate.form.expire_time_3months', 'generate.form.permanent', '永久', '三个月', 'Три месяца', 'Three Months', true],
            ['t', 'generate.form.expire_time_6months', 'generate.form.permanent', '永久', '六个月', 'Шесть месяцев', 'Six Months', true],
            ['t', 'generate.form.expire_time_9months', 'generate.form.permanent', '永久', '九个月', 'Девять месяцев', 'Nine Months', true],
            ['t', 'generate.form.expire_time_1year', 'generate.form.permanent', '永久', '一年', 'Один год', 'One Year', true],
            ['t', 'generate.form.expire_time_specific', 'generate.form.permanent', '永久', '具体时间', 'Конкретное время', 'Specific Time', false],
            ['t', 'generate.form.expire_time_today', 'generate.form.permanent', '永久', '设为当天结束', 'До конца сегодня', 'Set to end of today', false],
            ['t', 'generate.form.expire_time_confirm', 'generate.form.permanent', '永久', '确定', 'Подтвердить', 'Confirm', false],
        ];
        foreach ($fallbacks as [$translator, $key, $probe, $zhProbe, $zh, $ru, $en, $hasV3Default]) {
            if ($hasV3Default) {
                $v3Default = $translator . '("' . $probe . '").includes("月")?"' . $zh
                    . '":/[А-Яа-яЁё]/.test(' . $translator . '("' . $probe . '"))?"' . $ru . '":"' . $en . '"';
                $search = $translator . '("' . $key . '",{defaultValue:' . $v3Default . '})';
            } else {
                $search = $translator . '("' . $key . '")';
            }
            $replacement = '(()=>{const n="' . $key . '",i=' . $translator . '(n),r='
                . $translator . '("' . $probe . '");return "string"==typeof i&&i.includes(n)?(r.includes("'
                . $zhProbe . '")?"' . $zh . '":/[А-Яа-яЁё]/.test(r)?"' . $ru . '":"' . $en . '"):i})()';
            $content = $this->replaceExactlyOnce($content, $search, $replacement, $key . ' v4 fallback');
        }

        return $content;
    }

    private function patchV5Content(string $relative, string $content): string
    {
        if ($relative === 'public/assets/admin/index.html') {
            $pattern = '~(\./(?:locales/(?:en-US|ru-RU|zh-CN)\.js|assets/index-[A-Za-z0-9_-]+\.js))(?:\?v=smart-expiry-v\d+)?~';
            $patched = preg_replace($pattern, '$1?v=' . self::V5_MARKER, $content, -1, $count);
            if ($patched === null || $count !== 4) {
                throw new RuntimeException('SmartExpiry could not update the admin asset cache keys. No changes were applied.');
            }

            return $patched;
        }

        if (!$this->isAdminBundle($relative)) {
            if (!str_contains($content, self::V4_MARKER)) {
                throw $this->unsupported();
            }

            return str_replace(self::V4_MARKER, self::V5_MARKER, $content);
        }

        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-edit-v4*/',
            '/*smart-expiry-edit-v5*/',
            'edit-user v5 marker',
        );
        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-create-v4*/',
            '/*smart-expiry-create-v5*/',
            'create-user v5 marker',
        );

        $fallbacks = [
            ['e', 'edit.form.expire_time_6months', 'edit.form.expire_time_1month', '月', '六个月', 'Шесть месяцев', 'Six Months'],
            ['e', 'edit.form.expire_time_9months', 'edit.form.expire_time_1month', '月', '九个月', 'Девять месяцев', 'Nine Months'],
            ['e', 'edit.form.expire_time_1year', 'edit.form.expire_time_1month', '月', '一年', 'Один год', 'One Year'],
            ['t', 'generate.form.expire_time_1month', 'generate.form.permanent', '永久', '一个月', 'Один месяц', 'One Month'],
            ['t', 'generate.form.expire_time_3months', 'generate.form.permanent', '永久', '三个月', 'Три месяца', 'Three Months'],
            ['t', 'generate.form.expire_time_6months', 'generate.form.permanent', '永久', '六个月', 'Шесть месяцев', 'Six Months'],
            ['t', 'generate.form.expire_time_9months', 'generate.form.permanent', '永久', '九个月', 'Девять месяцев', 'Nine Months'],
            ['t', 'generate.form.expire_time_1year', 'generate.form.permanent', '永久', '一年', 'Один год', 'One Year'],
            ['t', 'generate.form.expire_time_specific', 'generate.form.permanent', '永久', '具体时间', 'Конкретное время', 'Specific Time'],
            ['t', 'generate.form.expire_time_today', 'generate.form.permanent', '永久', '设为当天结束', 'До конца сегодня', 'Set to end of today'],
            ['t', 'generate.form.expire_time_confirm', 'generate.form.permanent', '永久', '确定', 'Подтвердить', 'Confirm'],
        ];
        foreach ($fallbacks as [$translator, $key, $probe, $zhProbe, $zh, $ru, $en]) {
            $broken = '(()=>{const e="' . $key . '",n=' . $translator . '(e),i='
                . $translator . '("' . $probe . '");return "string"==typeof n&&n.includes(e)?(i.includes("'
                . $zhProbe . '")?"' . $zh . '":/[А-Яа-яЁё]/.test(i)?"' . $ru . '":"' . $en . '"):n})()';
            $fixed = '(()=>{const n="' . $key . '",i=' . $translator . '(n),r='
                . $translator . '("' . $probe . '");return "string"==typeof i&&i.includes(n)?(r.includes("'
                . $zhProbe . '")?"' . $zh . '":/[А-Яа-яЁё]/.test(r)?"' . $ru . '":"' . $en . '"):i})()';
            if (substr_count($content, $broken) === 1) {
                $content = str_replace($broken, $fixed, $content);
                continue;
            }
            if (substr_count($content, $fixed) !== 1) {
                throw new RuntimeException("SmartExpiry could not repair the {$key} fallback. No changes were applied.");
            }
        }

        return $content;
    }

    private function patchV6Content(string $relative, string $content): string
    {
        if ($relative === 'public/assets/admin/index.html') {
            $pattern = '~(\./(?:locales/(?:en-US|ru-RU|zh-CN)\.js|assets/index-[A-Za-z0-9_-]+\.js))(?:\?v=smart-expiry-v\d+)?~';
            $patched = preg_replace($pattern, '$1?v=' . self::V6_MARKER, $content, -1, $count);
            if ($patched === null || $count !== 4) {
                throw new RuntimeException('SmartExpiry could not update the admin asset cache keys. No changes were applied.');
            }

            return $patched;
        }

        if (!$this->isAdminBundle($relative)) {
            if (!str_contains($content, self::V5_MARKER)) {
                throw $this->unsupported();
            }

            return str_replace(self::V5_MARKER, self::V6_MARKER, $content);
        }

        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-edit-v5*/',
            '/*smart-expiry-edit-v6*/',
            'edit-user v6 marker',
        );
        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-create-v5*/',
            '/*smart-expiry-create-v6*/',
            'create-user v6 marker',
        );

        $createFallbacks = [
            ['generate.form.expire_time_1month', '一个月', 'Один месяц', 'One Month'],
            ['generate.form.expire_time_3months', '三个月', 'Три месяца', 'Three Months'],
            ['generate.form.expire_time_6months', '六个月', 'Шесть месяцев', 'Six Months'],
            ['generate.form.expire_time_9months', '九个月', 'Девять месяцев', 'Nine Months'],
            ['generate.form.expire_time_1year', '一年', 'Один год', 'One Year'],
            ['generate.form.expire_time_specific', '具体时间', 'Конкретное время', 'Specific Time'],
            ['generate.form.expire_time_today', '设为当天结束', 'До конца сегодня', 'Set to end of today'],
            ['generate.form.expire_time_confirm', '确定', 'Подтвердить', 'Confirm'],
        ];
        foreach ($createFallbacks as [$key, $zh, $ru, $en]) {
            $old = '(()=>{const n="' . $key . '",i=t(n),r=t("generate.form.permanent");return "string"==typeof i&&i.includes(n)?(r.includes("永久")?"'
                . $zh . '":/[А-Яа-яЁё]/.test(r)?"' . $ru . '":"' . $en . '"):i})()';
            $replacement = '(()=>{const n="' . $key . '",i=t(n),r=t("edit.form.expire_time_1month");return "string"==typeof i&&i.includes(n)?(r.includes("月")?"'
                . $zh . '":/[А-Яа-яЁё]/.test(r)?"' . $ru . '":"' . $en . '"):i})()';
            $content = $this->replaceExactlyOnce($content, $old, $replacement, $key . ' v6 language fallback');
        }

        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-create-v6*/H.useEffect(()=>{n&&RD()',
            '/*smart-expiry-create-v6*/H.useEffect(()=>{n||u(!1)},[n]);H.useEffect(()=>{n&&RD()',
            'create-user popover close effect',
        );

        $content = $this->replaceExactlyOnce(
            $content,
            'Q.jsxs("div",{className:"grid grid-cols-2 gap-4",children:[Q.jsx(TYt,{control:r.control,name:"expired_at"',
            'Q.jsxs("div",{className:"grid grid-cols-1 gap-4 sm:grid-cols-2",children:[Q.jsx(TYt,{control:r.control,name:"expired_at"',
            'create-user responsive expiry row',
        );

        $createStart = strpos($content, 'Q.jsxs(P$t,{open:d,onOpenChange:u');
        $createEnd = $createStart === false
            ? false
            : strpos($content, 'Q.jsx(TYt,{control:r.control,name:"plan_id"', $createStart);
        if ($createStart === false || $createEnd === false || $createEnd <= $createStart) {
            throw new RuntimeException('SmartExpiry could not isolate the create-user expiry controls. No changes were applied.');
        }
        $createUi = substr($content, $createStart, $createEnd - $createStart);
        $createUi = $this->replaceExactlyOnce(
            $createUi,
            'Q.jsx(B$t,{className:"w-auto p-0",align:"start",side:"top",sideOffset:4,onInteractOutside:e=>{e.preventDefault()},onEscapeKeyDown:e=>{e.preventDefault()},children:',
            'Q.jsx(B$t,{className:"w-auto p-0",align:"center",side:"bottom",sideOffset:8,collisionPadding:8,style:{width:"min(22rem, calc(100vw - 2rem))",maxHeight:"calc(100vh - 2rem)",overflowY:"auto",overscrollBehavior:"contain",touchAction:"pan-y"},children:',
            'create-user mobile expiry popover',
        );
        $content = substr($content, 0, $createStart) . $createUi . substr($content, $createEnd);

        return $content;
    }

    private function patchV7Content(string $relative, string $content): string
    {
        if ($relative === 'public/assets/admin/index.html') {
            $pattern = '~(\./(?:locales/(?:en-US|ru-RU|zh-CN)\.js|assets/index-[A-Za-z0-9_-]+\.js))(?:\?v=smart-expiry-v\d+)?~';
            $patched = preg_replace($pattern, '$1?v=' . self::MARKER, $content, -1, $count);
            if ($patched === null || $count !== 4) {
                throw new RuntimeException('SmartExpiry could not update the admin asset cache keys. No changes were applied.');
            }

            return $patched;
        }

        if (!$this->isAdminBundle($relative)) {
            if (!str_contains($content, self::V6_MARKER)) {
                throw $this->unsupported();
            }

            return str_replace(self::V6_MARKER, self::MARKER, $content);
        }

        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-edit-v6*/',
            '/*smart-expiry-edit-v7*/',
            'edit-user v7 marker',
        );
        $content = $this->replaceExactlyOnce(
            $content,
            '/*smart-expiry-create-v6*/',
            '/*smart-expiry-create-v7*/',
            'create-user v7 marker',
        );

        $createStart = strpos($content, 'Q.jsxs(P$t,{open:d,onOpenChange:u');
        $createEnd = $createStart === false
            ? false
            : strpos($content, 'Q.jsx(TYt,{control:r.control,name:"plan_id"', $createStart);
        if ($createStart === false || $createEnd === false || $createEnd <= $createStart) {
            throw new RuntimeException('SmartExpiry could not isolate the create-user expiry controls. No changes were applied.');
        }
        $createUi = substr($content, $createStart, $createEnd - $createStart);
        $createUi = $this->replaceExactlyOnce(
            $createUi,
            'Q.jsx("div",{className:"rounded-md border",children:Q.jsx(o$t,{mode:"single",selected:r.watch("expired_at")',
            'Q.jsx("div",{className:"hidden rounded-md border sm:block",children:Q.jsx(o$t,{mode:"single",selected:r.watch("expired_at")',
            'create-user desktop-only calendar',
        );
        $createUi = $this->replaceExactlyOnce(
            $createUi,
            'maxHeight:"calc(100vh - 2rem)",overflowY:"auto",overscrollBehavior:"contain",touchAction:"pan-y"',
            'maxHeight:"calc(100dvh - 2rem)",overflowY:"auto",overscrollBehavior:"contain",touchAction:"pan-y",WebkitOverflowScrolling:"touch"',
            'create-user mobile scroll behavior',
        );
        $content = substr($content, 0, $createStart) . $createUi . substr($content, $createEnd);

        return $content;
    }

    private function removeV7Content(string $relative, string $content): string
    {
        if ($relative === 'public/assets/admin/index.html') {
            $pattern = '~(\./(?:locales/(?:en-US|ru-RU|zh-CN)\.js|assets/index-[A-Za-z0-9_-]+\.js))\?v=' . preg_quote(self::MARKER, '~') . '~';
            $cleaned = preg_replace($pattern, '$1', $content, -1, $count);
            if ($cleaned === null || $count !== 4) {
                throw new RuntimeException('SmartExpiry could not remove its admin asset cache keys. No changes were applied.');
            }
            return $cleaned;
        }

        if (!$this->isAdminBundle($relative)) {
            $lines = preg_split('/(?<=\n)/', $content);
            if ($lines === false) {
                throw $this->unsupported();
            }
            $removed = 0;
            foreach ($lines as $key => $line) {
                if (str_contains($line, '"smart_expiry_marker')) {
                    unset($lines[$key]);
                    $removed++;
                }
            }
            if ($removed !== 2) {
                throw new RuntimeException("SmartExpiry could not identify its locale entries in {$relative}. No changes were applied.");
            }
            $content = implode('', $lines);
            $originalPermanent = match ($relative) {
                'public/assets/admin/locales/en-US.js' => 'Permanent',
                'public/assets/admin/locales/ru-RU.js' => 'Бессрочно',
                'public/assets/admin/locales/zh-CN.js' => '长期有效',
                default => throw $this->unsupported(),
            };
            $generatedPermanent = match ($relative) {
                'public/assets/admin/locales/en-US.js' => 'Permanent',
                'public/assets/admin/locales/ru-RU.js' => 'Навсегда',
                'public/assets/admin/locales/zh-CN.js' => '永久',
                default => throw $this->unsupported(),
            };
            $blockPattern = '~(        "permanent": )"' . preg_quote($generatedPermanent, '~') . '",\R'
                . '(?:        "expire_time_(?:1month|3months|6months|9months|1year|specific|today|confirm)": .*\R){8}~u';
            $content = preg_replace($blockPattern, '$1"' . $originalPermanent . '",' . "\n", $content, 1, $count);
            if ($content === null || $count !== 1) {
                throw new RuntimeException("SmartExpiry could not remove its generated locale entries in {$relative}. No changes were applied.");
            }
            $editPattern = '~(        "expire_time_3months": .*\R)'
                . '(?:        "expire_time_(?:6months|9months|1year)": .*\R){3}~u';
            $content = preg_replace($editPattern, '$1', $content, 1, $count);
            if ($content === null || $count !== 1) {
                throw new RuntimeException("SmartExpiry could not remove its edit locale entries in {$relative}. No changes were applied.");
            }
            return $content;
        }

        return $this->removeBundleContent($content);
    }

    private function removeBundleContent(string $content): string
    {
        $replace = function (string $owned, string $original, string $name) use (&$content): void {
            $content = $this->replaceExactlyOnce($content, $owned, $original, $name);
        };

        $replace('/*smart-expiry-edit-v7*/', '/*smart-expiry-edit-v6*/', 'edit-user v7 marker');
        $replace('/*smart-expiry-create-v7*/', '/*smart-expiry-create-v6*/', 'create-user v7 marker');
        $replace('className:"hidden rounded-md border sm:block"', 'className:"rounded-md border"', 'desktop-only calendar');
        $replace('maxHeight:"calc(100dvh - 2rem)",overflowY:"auto",overscrollBehavior:"contain",touchAction:"pan-y",WebkitOverflowScrolling:"touch"', 'maxHeight:"calc(100vh - 2rem)",overflowY:"auto",overscrollBehavior:"contain",touchAction:"pan-y"', 'mobile scroll behavior');

        $replace('/*smart-expiry-edit-v6*/', '/*smart-expiry-edit-v5*/', 'edit-user v6 marker');
        $replace('/*smart-expiry-create-v6*/', '/*smart-expiry-create-v5*/', 'create-user v6 marker');
        $replace('/*smart-expiry-create-v5*/H.useEffect(()=>{n||u(!1)},[n]);H.useEffect(()=>{n&&RD()', '/*smart-expiry-create-v5*/H.useEffect(()=>{n&&RD()', 'create-user close effect');
        $replace('Q.jsxs("div",{className:"grid grid-cols-1 gap-4 sm:grid-cols-2",children:[Q.jsx(TYt,{control:r.control,name:"expired_at"', 'Q.jsxs("div",{className:"grid grid-cols-2 gap-4",children:[Q.jsx(TYt,{control:r.control,name:"expired_at"', 'responsive expiry row');
        $replace('Q.jsx(B$t,{className:"w-auto p-0",align:"center",side:"bottom",sideOffset:8,collisionPadding:8,style:{width:"min(22rem, calc(100vw - 2rem))",maxHeight:"calc(100vh - 2rem)",overflowY:"auto",overscrollBehavior:"contain",touchAction:"pan-y"},children:', 'Q.jsx(B$t,{className:"w-auto p-0",align:"start",side:"top",sideOffset:4,onInteractOutside:e=>{e.preventDefault()},onEscapeKeyDown:e=>{e.preventDefault()},children:', 'mobile expiry popover');

        $replace('/*smart-expiry-edit-v5*/', '/*smart-expiry-edit-v4*/', 'edit-user v5 marker');
        $replace('/*smart-expiry-create-v5*/', '/*smart-expiry-create-v4*/', 'create-user v5 marker');
        $content = $this->reverseCurrentFallbacks($content);
        $replace = function (string $owned, string $original, string $name) use (&$content): void {
            $content = $this->replaceExactlyOnce($content, $owned, $original, $name);
        };
        $replace('/*smart-expiry-edit-v4*/', '/*smart-expiry-edit-v3*/', 'edit-user v4 marker');
        $replace('/*smart-expiry-create-v4*/', '/*smart-expiry-create-v3*/', 'create-user v4 marker');
        $content = $this->replaceExactlyOnce($content, '/*smart-expiry-edit-v3*/', '/*smart-expiry-edit-v2*/', 'edit-user v3 marker');
        $content = $this->replaceExactlyOnce($content, '/*smart-expiry-create-v3*/', '/*smart-expiry-create-v2*/', 'create-user v3 marker');
        $editGrid = 'Q.jsxs("div",{className:"grid grid-cols-3 gap-2",children:[Q.jsx(Lf,{type:"button",variant:"outline",className:"w-full min-w-0 px-1 text-xs sm:px-3 sm:text-sm",onClick:()=>{t.onChange(null),o(!1)}';
        $createGrid = 'Q.jsxs("div",{className:"grid grid-cols-3 gap-2",children:[Q.jsx(Lf,{type:"button",variant:"outline",className:"w-full min-w-0 px-1 text-xs sm:px-3 sm:text-sm",onClick:()=>{r.setValue("expired_at",null,{shouldDirty:!0,shouldValidate:!0}),u(!1)}';
        $content = $this->replaceExactlyOnce($content, $editGrid, str_replace('grid grid-cols-3', 'flex flex-wrap', $editGrid), 'edit button grid');
        $content = $this->replaceExactlyOnce($content, $createGrid, str_replace('grid grid-cols-3', 'flex flex-wrap', $createGrid), 'create button grid');
        if (substr_count($content, 'className:"w-full min-w-0 px-1 text-xs sm:px-3 sm:text-sm"') !== 12) {
            throw new RuntimeException('SmartExpiry could not identify its twelve expiry buttons. No changes were applied.');
        }
        $content = str_replace('className:"w-full min-w-0 px-1 text-xs sm:px-3 sm:text-sm"', 'className:"min-w-[5rem] flex-1"', $content);
        $content = $this->patchV2Content('public/assets/admin/assets/index-owned.js', $content, true);
        return $this->patchV1Content('public/assets/admin/assets/index-owned.js', $content, true);
    }

    private function reverseCurrentFallbacks(string $content): string
    {
        $keys = [
            'edit.form.expire_time_6months' => 'e',
            'edit.form.expire_time_9months' => 'e',
            'edit.form.expire_time_1year' => 'e',
            'generate.form.expire_time_1month' => 't',
            'generate.form.expire_time_3months' => 't',
            'generate.form.expire_time_6months' => 't',
            'generate.form.expire_time_9months' => 't',
            'generate.form.expire_time_1year' => 't',
            'generate.form.expire_time_specific' => 't',
            'generate.form.expire_time_today' => 't',
            'generate.form.expire_time_confirm' => 't',
        ];
        foreach ($keys as $key => $translator) {
            $pattern = '~\(\(\)=>\{const n="' . preg_quote($key, '~') . '".+?:i\}\)\(\)~';
            $content = preg_replace($pattern, $translator . '("' . $key . '")', $content, 1, $count);
            if ($content === null || $count !== 1) {
                throw new RuntimeException("SmartExpiry could not remove its {$key} fallback. No changes were applied.");
            }
        }
        return $content;
    }

    /**
     * @param array<string, string> $paths
     * @param array<string, string> $patched
     */
    private function writeAllOrRestore(array $paths, array $patched, string $expectedState): void
    {
        $originals = [];
        $written = [];
        try {
            foreach ($patched as $relative => $content) {
                $original = file_get_contents($paths[$relative]);
                if ($original === false) {
                    throw new RuntimeException("SmartExpiry could not read {$relative} before writing.");
                }
                $originals[$relative] = $original;
                $written[] = $relative;
                $this->atomicWrite($paths[$relative], $content, $relative);
                $verified = file_get_contents($paths[$relative]);
                if ($verified === false || !hash_equals(hash('sha256', $content), hash('sha256', $verified))) {
                    throw new RuntimeException("SmartExpiry could not verify {$relative} after writing.");
                }
            }
            [, $actualState] = $this->validateFiles();
            if ($actualState !== $expectedState) {
                throw new RuntimeException("SmartExpiry post-write verification expected {$expectedState}, got {$actualState}.");
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($written) as $relative) {
                try {
                    $this->atomicWrite($paths[$relative], $originals[$relative], $relative . ' rollback');
                } catch (\Throwable) {
                    // Preserve the original failure; every rollback uses the operation-start snapshot.
                }
            }
            throw $exception;
        }
    }

    private function atomicWrite(string $path, string $content, string $relative): void
    {
        $permissions = fileperms($path);
        $temporary = tempnam(dirname($path), '.smart-expiry-');
        if ($temporary === false) {
            throw new RuntimeException("SmartExpiry could not create a temporary file for {$relative}.");
        }
        try {
            if (file_put_contents($temporary, $content) !== strlen($content)) {
                throw new RuntimeException("SmartExpiry could not write {$relative}.");
            }
            if ($permissions !== false) {
                @chmod($temporary, $permissions & 0777);
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException("SmartExpiry could not atomically replace {$relative}.");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function replaceExactlyOnce(string $content, string $search, string $replacement, string $name): string
    {
        if (substr_count($content, $search) !== 1) {
            throw new RuntimeException("SmartExpiry could not find the unique {$name} anchor. No changes were applied.");
        }

        return str_replace($search, $replacement, $content);
    }

    private function isAdminBundle(string $relative): bool
    {
        return str_starts_with($relative, 'public/assets/admin/assets/index-')
            && str_ends_with($relative, '.js');
    }

    private function hasV2Marker(string $relative, string $content): bool
    {
        if ($this->isAdminBundle($relative)) {
            return str_contains($content, 'smart-expiry-edit-v2')
                && str_contains($content, 'smart-expiry-create-v2');
        }

        return str_contains($content, self::V2_MARKER);
    }

    private function hasV3Marker(string $relative, string $content): bool
    {
        if ($this->isAdminBundle($relative)) {
            return str_contains($content, 'smart-expiry-edit-v3')
                && str_contains($content, 'smart-expiry-create-v3');
        }

        return str_contains($content, self::V3_MARKER);
    }

    private function hasV4Marker(string $relative, string $content): bool
    {
        if ($this->isAdminBundle($relative)) {
            return str_contains($content, 'smart-expiry-edit-v4')
                && str_contains($content, 'smart-expiry-create-v4');
        }

        return str_contains($content, self::V4_MARKER);
    }

    private function hasV5Marker(string $relative, string $content): bool
    {
        if ($this->isAdminBundle($relative)) {
            return str_contains($content, 'smart-expiry-edit-v5')
                && str_contains($content, 'smart-expiry-create-v5');
        }

        return str_contains($content, self::V5_MARKER);
    }

    private function hasV6Marker(string $relative, string $content): bool
    {
        if ($this->isAdminBundle($relative)) {
            return str_contains($content, 'smart-expiry-edit-v6')
                && str_contains($content, 'smart-expiry-create-v6');
        }

        return str_contains($content, self::V6_MARKER);
    }

    private function hasV7Marker(string $relative, string $content): bool
    {
        if ($this->isAdminBundle($relative)) {
            return str_contains($content, 'smart-expiry-edit-v7')
                && str_contains($content, 'smart-expiry-create-v7');
        }

        return str_contains($content, self::MARKER);
    }

    private function unsupported(): RuntimeException
    {
        return new RuntimeException("Unsupported Xboard admin build.\nSmartExpiry patch was not applied.");
    }
}
