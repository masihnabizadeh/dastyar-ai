<?php
if (!defined('ABSPATH')) exit;

class DSS_Conversation {
    const EXP_SECONDS = 86400; // 1 day

    protected static function key($sid){ return 'dss_ctx_' . md5($sid); }
    protected static function load($sid){
        $ctx = get_transient(self::key($sid));
        if (!is_array($ctx)) $ctx = ['phase'=>'start','intent'=>null,'budget'=>null,'category'=>null,'usage'=>null];
        return $ctx;
    }
    protected static function save($sid, $ctx){ set_transient(self::key($sid), $ctx, self::EXP_SECONDS); }

    public static function handle($sid, $message){
        $ctx = self::load($sid);
        $opts = get_option('dss_options', []);

        $m = mb_strtolower($message, 'UTF-8');

        if ($ctx['phase']==='start'){
            $f = isset($opts['features']) && is_array($opts['features']) ? $opts['features'] : ['budget'=>'yes','category'=>'yes','usage'=>'yes'];
            $quick = [];
            if (isset($f['budget']) && $f['budget']==='yes') $quick[] = 'قیمت';
            if (isset($f['category']) && $f['category']==='yes') $quick[] = 'دسته‌بندی';
            if (isset($f['usage']) && $f['usage']==='yes') $quick[] = 'نوع استفاده';

            if (self::contains_any($m, ['قیمت','بودجه','زیر','تا','تومن','میلیون'])){
                $ctx['intent'] = 'budget'; $ctx['phase'] = 'ask_budget';
                self::save($sid,$ctx);
                return ['ok'=>true,'text'=>'بودجه‌ات حدوداً چقدره؟ (مثلاً: زیر ۳ میلیون، تا ۵ میلیون، بین ۱۰ تا ۱۵)','quick'=>['زیر ۳ میلیون','۳ تا ۶ میلیون','۶ تا ۱۰ میلیون','بیشتر از ۱۰ میلیون']];
            }
            if (self::contains_any($m, ['دسته','گروه','طبقه','نوع محصول','محصول'])){
                $ctx['intent'] = 'category'; $ctx['phase'] = 'ask_category';
                self::save($sid,$ctx);
                return ['ok'=>true,'text'=>'چه دسته‌ای مد نظرته؟','quick'=>['انگشتر','گردنبند','دستبند','ست نقره','اکسسوری']];
            }
            if (self::contains_any($m, ['استفاده','کاربرد','برای','هدیه','روزمره','مجلس','عروسی','کادو'])){
                $ctx['intent'] = 'usage'; $ctx['phase'] = 'ask_usage';
                self::save($sid,$ctx);
                return ['ok'=>true,'text'=>'برای چه موقعیتی؟','quick'=>['هدیه','روزمره','مجلسی','ست زنانه/مردانه']];
            }
            self::save($sid,$ctx);
            $greet = DSS_Onboarding::compile_greeting();
            return ['ok'=>true,'text'=>$greet,'quick'=>$quick];
        }

        if ($ctx['phase']==='ask_budget'){
            $budget = self::parse_budget($m);
            if ($budget){
                $ctx['budget'] = $budget; $ctx['phase'] = 'suggest';
                self::save($sid,$ctx);
                $resp = DSS_AI_Engine::suggest_structured(self::build_query_for_ai($ctx, $message));
                $resp['quick'] = ['ارزان‌تر','گران‌تر','مشابه بیشتر'];
                return $resp;
            } else {
                return ['ok'=>true,'text'=>'عدد بودجه را دقیق‌تر بگو (مثلاً: زیر ۴ میلیون یا تا ۱۰ میلیون).','quick'=>['زیر ۳ میلیون','۳ تا ۶ میلیون','۶ تا ۱۰ میلیون','بیشتر از ۱۰ میلیون']];
            }
        }

        if ($ctx['phase']==='ask_category'){
            $ctx['category'] = trim($message);
            $ctx['phase'] = 'suggest';
            self::save($sid,$ctx);
            $resp = DSS_AI_Engine::suggest_structured(self::build_query_for_ai($ctx, $message));
            $resp['quick'] = ['ارزان‌تر','گران‌تر','مشابه بیشتر'];
            return $resp;
        }

        if ($ctx['phase']==='ask_usage'){
            $ctx['usage'] = trim($message);
            $ctx['phase'] = 'suggest';
            self::save($sid,$ctx);
            $resp = DSS_AI_Engine::suggest_structured(self::build_query_for_ai($ctx, $message));
            $resp['quick'] = ['ارزان‌تر','گران‌تر','مشابه بیشتر'];
            return $resp;
        }

        if ($ctx['phase']==='suggest'){
            if (self::contains_any($m, ['ارزان‌تر','ارزانتر','پایین‌تر'])){
                if (is_array($ctx['budget'])){
                    $ctx['budget']['max'] = max(0, intval(($ctx['budget']['max']??0)*0.8));
                } else {
                    $ctx['budget'] = ['max'=>5000000,'min'=>0];
                }
                self::save($sid,$ctx);
                $resp = DSS_AI_Engine::suggest_structured(self::build_query_for_ai($ctx, $message));
                $resp['quick'] = ['ارزان‌تر','گران‌تر','مشابه بیشتر'];
                return $resp;
            }
            if (self::contains_any($m, ['گران‌تر','قوی‌تر'])){
                if (is_array($ctx['budget'])){
                    $ctx['budget']['min'] = intval(($ctx['budget']['min']??0) + 1000000);
                } else {
                    $ctx['budget'] = ['min'=>5000000];
                }
                self::save($sid,$ctx);
                $resp = DSS_AI_Engine::suggest_structured(self::build_query_for_ai($ctx, $message));
                $resp['quick'] = ['ارزان‌تر','گران‌تر','مشابه بیشتر'];
                return $resp;
            }
            $resp = DSS_AI_Engine::suggest_structured(self::build_query_for_ai($ctx, $message));
            $resp['quick'] = ['ارزان‌تر','گران‌تر','مشابه بیشتر'];
            return $resp;
        }

        return ['ok'=>true,'text'=>'برای ادامه، یکی از مسیرها را انتخاب کن: قیمت، دسته یا کاربرد.','quick'=>['قیمت','دسته‌بندی','نوع استفاده']];
    }

    protected static function build_query_for_ai($ctx, $message){
        $parts = [];
        if (!empty($ctx['budget'])){
            $b = $ctx['budget'];
            if (isset($b['min'])) $parts[] = 'حداقل بودجه: '.$b['min'];
            if (isset($b['max'])) $parts[] = 'حداکثر بودجه: '.$b['max'];
        }
        if (!empty($ctx['category'])) $parts[] = 'دسته: '.$ctx['category'];
        if (!empty($ctx['usage'])) $parts[] = 'کاربرد: '.$ctx['usage'];
        $prefix = implode(' | ', $parts);
        return ($prefix ? $prefix.' — ' : '') . $message;
    }

    protected static function contains_any($text, $arr){
        foreach ($arr as $w){
            if (mb_strpos($text, $w) !== false) return true;
        }
        return false;
    }

    protected static function normalize_digits($s){
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        for ($i=0; $i<10; $i++){
            $s = str_replace($fa[$i], (string)$i, $s);
            $s = str_replace($ar[$i], (string)$i, $s);
        }
        return $s;
    }

    protected static function parse_budget($text){
        $text = self::normalize_digits($text);
        if (preg_match_all('/(\d+)[\s\-]*میلیون/u', $text, $m)){
            $nums = array_map('intval', $m[1]);
            sort($nums);
            if (self::contains_any($text,['زیر','کمتر','تا'])){
                return ['max'=>$nums[0]*1000000];
            }
            if (self::contains_any($text,['بین'])){
                return ['min'=>$nums[0]*1000000,'max'=>($nums[1]??$nums[0])*1000000];
            }
            if (self::contains_any($text,['بالا','بیشتر'])){
                return ['min'=>end($nums)*1000000];
            }
            return ['max'=>$nums[0]*1000000];
        }
        if (preg_match('/(\d[\d\,\.]*)/u', $text, $m2)){
            $n = intval(str_replace([',','.'],'',$m2[1]));
            if ($n>0){
                if (self::contains_any($text,['زیر','کمتر','تا'])) return ['max'=>$n];
                if (self::contains_any($text,['بین'])) return ['min'=>$n];
                if (self::contains_any($text,['بالا','بیشتر'])) return ['min'=>$n];
                return ['max'=>$n];
            }
        }
        return null;
    }
}
