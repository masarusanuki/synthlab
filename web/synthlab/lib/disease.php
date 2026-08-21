<?php
// disease.php -- 疾患プリセット
//
// ▲重要な注意▲
// 以下の疾患モデルは論文 (MEDINFO 2022 / 生物試料分析 2024 / MEDINFO 2025) には
// 含まれていない。元論文が扱うのは「疾患名のついていない成人」および
// 「入院患者全体」の分布であり、疾患別のパラメータは公表されていない。
// ここに置いた効果量は、臨床的に一般に知られた検査値の動き方を
// AI (Claude) が解釈して構成したものである。教育・開発・動作確認用であり、
// 疫学的な妥当性は保証されない。
//
// 効果の指定方法:
//   shift : 対数スケールの mu を sigma 単位で平行移動する量
//   sd    : 対数スケールの sigma にかける倍率 (既定 1.0)
//   min   : 出力スケール (検査値そのもの) の下限
//   max   : 出力スケール (検査値そのもの) の上限
//
// min / max は log(y - d) に変換して切断多変量正規分布の切断区間として扱う。
// これにより「糖尿病なら HbA1c は 6.5% 以上」のような診断閾値を保ちながら、
// 他項目との相関を崩さずサンプリングできる。

declare(strict_types=1);

function synthlab_diseases(): array
{
    return [
        'none' => [
            'label' => '疾患名なし (健常成人)',
            'desc'  => '論文が対象とした「疾患名をもたない成人」に相当する。'
                     . '基準範囲から復元した lnorm3 をそのまま使う。',
            'effects' => [],
        ],

        't2dm' => [
            'label' => '2型糖尿病',
            'desc'  => 'HbA1c と空腹時血糖を診断域まで上げ、脂質と腎機能に軽い影響を与える。',
            'effects' => [
                'HbA1c' => ['shift' => 3.5, 'sd' => 2.0, 'min' => 6.5],
                'Glu'   => ['shift' => 3.5, 'sd' => 1.8, 'min' => 126.0],
                'TG'    => ['shift' => 0.8, 'sd' => 1.2],
                'HDL'   => ['shift' => -0.5],
                'eGFR'  => ['shift' => -0.4],
                'UA'    => ['shift' => 0.3],
                'gGTP'  => ['shift' => 0.5],
            ],
        ],

        'ckd' => [
            'label' => '慢性腎臓病 (G3b-G4)',
            'desc'  => 'eGFR を 45 未満に抑え、クレアチニン・尿素窒素・カリウム・リンを上げ、'
                     . '腎性貧血として Hb を下げる。',
            'effects' => [
                'eGFR'  => ['shift' => -3.0, 'sd' => 1.4, 'max' => 44.9],
                'CRE'   => ['shift' => 3.5, 'sd' => 2.0, 'min' => 1.3],
                'UN'    => ['shift' => 2.5, 'sd' => 1.6],
                'K'     => ['shift' => 1.0, 'sd' => 1.3],
                'IP'    => ['shift' => 1.0, 'sd' => 1.2],
                'Ca'    => ['shift' => -0.8],
                'Hb'    => ['shift' => -1.8, 'sd' => 1.2],
                'UA'    => ['shift' => 1.5, 'sd' => 1.2],
                'Alb'   => ['shift' => -1.0],
            ],
        ],

        'liver' => [
            'label' => '慢性肝機能障害 (脂肪肝・慢性肝炎)',
            'desc'  => 'トランスアミナーゼと γ-GT を上げる。AST-ALT-γGT の強い正相関が効く。',
            'effects' => [
                'ALT'   => ['shift' => 3.5, 'sd' => 1.8, 'min' => 50.0],
                'AST'   => ['shift' => 3.0, 'sd' => 1.7],
                'gGTP'  => ['shift' => 2.0, 'sd' => 1.5],
                'LD'    => ['shift' => 1.0, 'sd' => 1.2],
                'T.Bil' => ['shift' => 0.8],
                'ALP'   => ['shift' => 0.8],
                'Alb'   => ['shift' => -1.2],
                'PLT'   => ['shift' => -0.8],
                'CHE'   => ['shift' => -0.8],
                'TG'    => ['shift' => 1.0, 'sd' => 1.2],
            ],
        ],

        'cirrhosis' => [
            'label' => '肝硬変 (非代償期)',
            'desc'  => '合成能低下 (Alb・ChE 低下) と門脈圧亢進 (血小板低下)、'
                     . 'ビリルビン上昇を同時に表現する。',
            'effects' => [
                'Alb'   => ['shift' => -3.5, 'sd' => 1.5, 'max' => 3.4],
                'PLT'   => ['shift' => -3.0, 'sd' => 1.5, 'max' => 120.0],
                'CHE'   => ['shift' => -3.0, 'sd' => 1.4],
                'T.Bil' => ['shift' => 2.5, 'sd' => 1.8, 'min' => 2.0],
                'D.Bil' => ['shift' => 2.5, 'sd' => 1.8],
                'AST'   => ['shift' => 2.0, 'sd' => 1.5],
                'ALT'   => ['shift' => 1.2, 'sd' => 1.4],
                'TP'    => ['shift' => -1.2],
                'Na'    => ['shift' => -1.0],
                'UN'    => ['shift' => -0.5],
                'Hb'    => ['shift' => -1.5],
            ],
        ],

        'dyslip' => [
            'label' => '脂質異常症',
            'desc'  => 'LDL-C と総コレステロールを診療域まで上げ、HDL-C を下げる。',
            'effects' => [
                'LdL-C' => ['shift' => 2.5, 'sd' => 1.4, 'min' => 140.0],
                'TC'    => ['shift' => 2.5, 'sd' => 1.4, 'min' => 220.0],
                'TG'    => ['shift' => 1.8, 'sd' => 1.4],
                'HDL'   => ['shift' => -1.0, 'sd' => 1.1],
            ],
        ],

        'anemia_ida' => [
            'label' => '鉄欠乏性貧血',
            'desc'  => 'Hb を貧血域に下げ、MCV・MCH を小球性低色素性のパターンに動かす。',
            'effects' => [
                'Hb'   => ['shift' => -3.5, 'sd' => 1.5, 'max' => 11.0],
                'Ht'   => ['shift' => -3.0, 'sd' => 1.4],
                'RBC'  => ['shift' => -1.8, 'sd' => 1.3],
                'MCV'  => ['shift' => -3.5, 'sd' => 1.6, 'max' => 80.0],
                'MCH'  => ['shift' => -3.5, 'sd' => 1.6],
                'MCHC' => ['shift' => -2.0, 'sd' => 1.3],
                'PLT'  => ['shift' => 0.8, 'sd' => 1.2],
            ],
        ],

        'infection' => [
            'label' => '急性炎症・感染症',
            'desc'  => 'CRP と白血球数を強く上げる。急性期反応として Alb 低下も伴う。',
            'effects' => [
                'CRP' => ['shift' => 5.0, 'sd' => 1.6, 'min' => 1.0],
                'WBC' => ['shift' => 3.0, 'sd' => 1.6, 'min' => 10.0],
                'PLT' => ['shift' => 0.5, 'sd' => 1.2],
                'Alb' => ['shift' => -1.5],
                'Na'  => ['shift' => -0.8],
                'LD'  => ['shift' => 0.8],
                'AST' => ['shift' => 0.5],
            ],
        ],

        'hyperuricemia' => [
            'label' => '高尿酸血症・痛風',
            'desc'  => '尿酸を 7.0 mg/dL 以上に固定し、腎機能と脂質に軽い影響を与える。',
            'effects' => [
                'UA'   => ['shift' => 3.0, 'sd' => 1.4, 'min' => 7.0],
                'CRE'  => ['shift' => 0.6],
                'eGFR' => ['shift' => -0.6],
                'TG'   => ['shift' => 0.9, 'sd' => 1.2],
                'gGTP' => ['shift' => 0.6],
            ],
        ],

        'mets' => [
            'label' => 'メタボリックシンドローム',
            'desc'  => '個々の項目は軽度異常だが、糖・脂質・肝・尿酸が同時に動く。',
            'effects' => [
                'TG'    => ['shift' => 1.6, 'sd' => 1.3],
                'HDL'   => ['shift' => -1.3],
                'Glu'   => ['shift' => 1.4, 'sd' => 1.2],
                'HbA1c' => ['shift' => 1.2, 'sd' => 1.2],
                'ALT'   => ['shift' => 1.3, 'sd' => 1.3],
                'gGTP'  => ['shift' => 1.3, 'sd' => 1.3],
                'UA'    => ['shift' => 0.9],
                'CRP'   => ['shift' => 1.0, 'sd' => 1.2],
            ],
        ],
    ];
}

/**
 * 疾患効果を lnorm3 パラメータ列に適用する。
 *
 * mu / sigma だけを書き換え、min / max は 'dmin' / 'dmax' として記録するに留める。
 * 切断区間 (lo / hi) の確定は engine.php の sl_params() が
 * **シフト後の mu, sigma を使って**行う。
 *
 * この順序が重要である。切断区間をシフト前の mu, sigma から作ってしまうと、
 * 疾患シフトで平均が旧上限を越えたときに区間が潰れ、
 * 生成値が定数になる (例: 慢性腎臓病の CRE)。
 *
 * @param array $params key => ['mu','sigma','d',...]
 * @return array 同じ形式。'dmin' / 'dmax' が付く場合がある。
 */
function apply_disease(array $params, string $disease): array
{
    $all = synthlab_diseases();
    if (!isset($all[$disease])) {
        return $params;
    }
    foreach ($all[$disease]['effects'] as $key => $e) {
        if (!isset($params[$key])) {
            continue;
        }
        $p = $params[$key];
        $sd0 = $p['sigma'];
        if (isset($e['shift'])) {
            $p['mu'] += (float)$e['shift'] * $sd0;
        }
        if (isset($e['sd'])) {
            $p['sigma'] = $sd0 * (float)$e['sd'];
        }
        if (isset($e['min'])) {
            $p['dmin'] = (float)$e['min'];
        }
        if (isset($e['max'])) {
            $p['dmax'] = (float)$e['max'];
        }
        $params[$key] = $p;
    }
    return $params;
}

/** 疾患コード => 表示名 の対応 (ログ表示などで使う) */
function synthlab_dis_labels(): array
{
    $out = [];
    foreach (synthlab_diseases() as $k => $v) {
        $out[$k] = (string)$v['label'];
    }
    return $out;
}
