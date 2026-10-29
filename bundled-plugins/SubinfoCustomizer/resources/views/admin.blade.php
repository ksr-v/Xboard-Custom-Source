@php
    $subInfoCustomizerOriginalHtml = view()->file($__subInfoCustomizerOriginalPath, $__subInfoCustomizerOriginalData)->render();
    echo \Plugin\SubinfoCustomizer\Services\AdminIntegration::inject($subInfoCustomizerOriginalHtml);
@endphp
