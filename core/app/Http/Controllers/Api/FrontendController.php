<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Frontend;
use App\Models\Language;

class FrontendController extends Controller
{
    public function logoFavicon()
    {
        $data = [
            'logo'    => siteLogo(),
            'favicon' => siteFavicon(),
        ];
        return getResponse('logo_favicon', 'success', 'Logo & Favicon', $data);
    }

    public function language($code)
    {
        $language = Language::where('code', $code)->first();

        if (!$language) {
            return getResponse('not_found', 'error', 'Language not found');
        }

        $languages = Language::get();

        $path        = base_path() . "/resources/lang/$code.json";
        $fileContent = file_get_contents($path);

        $data = [
            'languages' => $languages,
            'file'      => $fileContent,
        ];

        return getResponse('language', 'success', 'Language Details', $data);
    }

    public function generalSetting()
    {
        return getResponse('general_setting', 'success', 'General setting data', ['general_setting' => gs()]);
    }

    public function policy()
    {
        $activeTemplate = gs('active_template');
        $policy         = Frontend::where('template_name', $activeTemplate)->where('data_keys', 'policy_pages.element')->get();
        return getResponse('policy_page', 'success', 'Policy & Terms and condition page', ['policy' => $policy]);
    }

    public function faq()
    {
        $activeTemplate = gs('active_template');
        $faqs           = Frontend::where('template_name', $activeTemplate)->where('data_keys', 'faq.element')->get();
        return getResponse('faq', 'success', 'Faq List', ['faqs' => $faqs]);
    }

}
