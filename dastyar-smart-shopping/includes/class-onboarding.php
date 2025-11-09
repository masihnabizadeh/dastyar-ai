<?php
if (!defined('ABSPATH')) exit;

class DSS_Onboarding {

    public static function compile_greeting(){
        $o = get_option('dss_options', []);
        $tpl = isset($o['greeting_template']) ? $o['greeting_template'] : 'سلام! من دستیار خرید {store_name} هستم.';
        $persona = isset($o['persona']) ? $o['persona'] : 'friendly';
        $persona_map = [
            'friendly' => 'لحن دوستانه و صمیمی',
            'luxury'   => 'لحن رسمی و لوکس',
            'expert'   => 'لحن تخصصی و دقیق',
            'playful'  => 'لحن سرزنده و صمیمی'
        ];
        $persona_desc = isset($persona_map[$persona]) ? $persona_map[$persona] : $persona_map['friendly'];

        $store_name = get_bloginfo('name');
        $cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>true,'number'=>5]);
        $cat_names = [];
        if (!is_wp_error($cats)){
            foreach ($cats as $c){ $cat_names[] = $c->name; }
        }
        $top_categories = $cat_names ? implode('، ', $cat_names) : 'دسته‌های فروشگاه';

        $repl = [
            '{store_name}'   => $store_name,
            '{persona_desc}' => $persona_desc,
            '{top_categories}' => $top_categories,
            '{today_date}'   => date_i18n('Y/m/d')
        ];
        return strtr($tpl, $repl);
    }
}
