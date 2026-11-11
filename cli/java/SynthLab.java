// SynthLab.java -- 擬似血液検査値ジェネレータ (Java 版 CLI)
//
// ================================  入力について  ================================
//
// このツールは **実データを入力しない (できない)**。入力は次の2種類だけである。
//
//   (A) 生成条件 — コマンドライン引数
//       人数、検査項目、性別、受診回数、乱数種など。何をどれだけ作るか。
//
//   (B) パラメータ源 — JSON ファイル 2 つ (--catalog / --correlation)
//       data/catalog.json      各検査項目の基準範囲 (下限・上限・中央値)
//       data/correlation.json  検査項目間の相関
//
//   実際の患者データは要らないし、渡す口もない。
//   (B) の基準範囲 3 つの値から分布を閉じた形で復元し、そこから乱数を引く。
//
// ================================================================================
//
// Python 版 (cli/synthlab.py) と同じ入力ファイル・同じアルゴリズムを使う。
// 外部ライブラリに依存しないので、JSON パーサと線形代数も自前で持つ。
//
// 元になった研究
//   [P1] Shiro M., Kagawa R. (2022) Stud Health Technol Inform 290:1066-1067.
//   [P2] 城 真範 (2024) 生物試料分析 47(4):113-121.
//   [P3] Furukawa T., Kagawa R., Sanuki M., Shiro M. (2025) MEDINFO 2025:1854-1855.
//
// ビルドと実行:
//   javac SynthLab.java
//   java SynthLab --explain
//   java SynthLab --n 500 --mode cross -o out.csv

import java.io.BufferedWriter;
import java.io.FileWriter;
import java.io.IOException;
import java.io.PrintWriter;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Random;

public class SynthLab {

    // =====================================================================
    // 最小限の JSON パーサ (外部ライブラリを使わないため)
    // =====================================================================
    static final class Json {
        private final String s;
        private int i;

        private Json(String s) { this.s = s; this.i = 0; }

        static Object parse(String text) {
            Json p = new Json(text);
            p.ws();
            Object v = p.value();
            p.ws();
            return v;
        }

        private void ws() {
            while (i < s.length() && Character.isWhitespace(s.charAt(i))) i++;
        }

        private Object value() {
            char c = s.charAt(i);
            switch (c) {
                case '{': return obj();
                case '[': return arr();
                case '"': return str();
                case 't': i += 4; return Boolean.TRUE;
                case 'f': i += 5; return Boolean.FALSE;
                case 'n': i += 4; return null;
                default:  return num();
            }
        }

        private Map<String, Object> obj() {
            Map<String, Object> m = new LinkedHashMap<>();
            i++; ws();
            if (s.charAt(i) == '}') { i++; return m; }
            while (true) {
                ws();
                String k = str();
                ws();
                i++;                       // ':'
                ws();
                m.put(k, value());
                ws();
                char c = s.charAt(i++);
                if (c == '}') return m;    // ',' なら継続
            }
        }

        private List<Object> arr() {
            List<Object> a = new ArrayList<>();
            i++; ws();
            if (s.charAt(i) == ']') { i++; return a; }
            while (true) {
                ws();
                a.add(value());
                ws();
                char c = s.charAt(i++);
                if (c == ']') return a;
            }
        }

        private String str() {
            StringBuilder b = new StringBuilder();
            i++;                            // 開きの "
            while (true) {
                char c = s.charAt(i++);
                if (c == '"') break;
                if (c == '\\') {
                    char e = s.charAt(i++);
                    switch (e) {
                        case 'n': b.append('\n'); break;
                        case 't': b.append('\t'); break;
                        case 'r': b.append('\r'); break;
                        case 'b': b.append('\b'); break;
                        case 'f': b.append('\f'); break;
                        case 'u':
                            b.append((char) Integer.parseInt(s.substring(i, i + 4), 16));
                            i += 4;
                            break;
                        default: b.append(e);
                    }
                } else {
                    b.append(c);
                }
            }
            return b.toString();
        }

        private Double num() {
            int st = i;
            while (i < s.length() && "+-0123456789.eE".indexOf(s.charAt(i)) >= 0) i++;
            return Double.valueOf(s.substring(st, i));
        }
    }

    @SuppressWarnings("unchecked")
    static Map<String, Object> asMap(Object o) { return (Map<String, Object>) o; }
    @SuppressWarnings("unchecked")
    static List<Object> asList(Object o) { return (List<Object>) o; }
    static double asD(Object o) { return ((Number) o).doubleValue(); }

    // =====================================================================
    // 正規分布
    // =====================================================================
    static final double[] A = {-3.969683028665376e+01, 2.209460984245205e+02,
        -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01,
        2.506628277459239e+00};
    static final double[] B = {-5.447609879822406e+01, 1.615858368580409e+02,
        -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01};
    static final double[] C = {-7.784894002430293e-03, -3.223964580411365e-01,
        -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00,
        2.938163982698783e+00};
    static final double[] D = {7.784695709041462e-03, 3.224671290700398e-01,
        2.445134137142996e+00, 3.754408661907416e+00};

    /** 標準正規の分位点 (Acklam 近似)。C++/Python 版と同一の係数。 */
    static double ppf(double p) {
        if (p <= 1e-16) p = 1e-16;
        if (p >= 1 - 1e-16) p = 1 - 1e-16;
        double q, r;
        if (p < 0.02425) {
            q = Math.sqrt(-2 * Math.log(p));
            return (((((C[0] * q + C[1]) * q + C[2]) * q + C[3]) * q + C[4]) * q + C[5])
                 / ((((D[0] * q + D[1]) * q + D[2]) * q + D[3]) * q + 1);
        }
        if (p > 1 - 0.02425) {
            q = Math.sqrt(-2 * Math.log(1 - p));
            return -(((((C[0] * q + C[1]) * q + C[2]) * q + C[3]) * q + C[4]) * q + C[5])
                 / ((((D[0] * q + D[1]) * q + D[2]) * q + D[3]) * q + 1);
        }
        q = p - 0.5;
        r = q * q;
        return (((((A[0] * r + A[1]) * r + A[2]) * r + A[3]) * r + A[4]) * r + A[5]) * q
             / (((((B[0] * r + B[1]) * r + B[2]) * r + B[3]) * r + B[4]) * r + 1);
    }

    /** 標準正規の累積分布関数。Java に erfc が無いので Zelen & Severo 近似を使う。 */
    static double cdf(double x) {
        if (Double.isInfinite(x)) return x > 0 ? 1.0 : 0.0;
        boolean neg = x < 0;
        double z = Math.abs(x);
        if (z > 40) return neg ? 0.0 : 1.0;
        double t = 1.0 / (1.0 + 0.2316419 * z);
        double poly = t * (0.319381530 + t * (-0.356563782 + t * (1.781477937
                    + t * (-1.821255978 + t * 1.330274429))));
        double upper = Math.exp(-0.5 * z * z) / Math.sqrt(2 * Math.PI) * poly;
        return neg ? upper : 1.0 - upper;
    }

    // =====================================================================
    // lnorm3
    // =====================================================================
    /** 基準範囲の下限 L・上限 U と中央値 M から (mu, sigma, d) を復元する。 */
    static double[] lnorm3FromThree(double L, double U, double M, double z) {
        if (!(L < M && M < U)) {
            throw new IllegalArgumentException("L < M < U が必要: " + L + "," + M + "," + U);
        }
        double den = L + U - 2.0 * M;
        boolean degenerate = Math.abs(den) < 1e-12 * Math.max(1.0, Math.abs(L) + Math.abs(U));
        double d = degenerate ? M - 20.0 * (U - L) : (L * U - M * M) / den;
        if (!degenerate && d >= L) { d = L - 20.0 * (U - L); degenerate = true; }
        double mu, sigma;
        if (degenerate) {
            // 退化時は公表値である L, U を厳密に再現する
            mu = (Math.log(U - d) + Math.log(L - d)) / 2.0;
            sigma = (Math.log(U - d) - Math.log(L - d)) / (2.0 * z);
        } else {
            mu = Math.log(M - d);
            sigma = Math.log((U - d) / (M - d)) / z;
        }
        return new double[]{mu, sigma, d};
    }

    /** 偏差値 T = 50 + 10 (log(x-d) - mu)/sigma */
    static double deviation(double x, double mu, double sigma, double d) {
        return 50.0 + 10.0 * (Math.log(x - d) - mu) / sigma;
    }

    // =====================================================================
    // 線形代数
    // =====================================================================
    static double[][] cholesky(double[][] a) {
        int n = a.length;
        double[][] L = new double[n][n];
        for (int i = 0; i < n; i++) {
            for (int j = 0; j <= i; j++) {
                double s = a[i][j];
                for (int k = 0; k < j; k++) s -= L[i][k] * L[j][k];
                if (i == j) {
                    if (!(s > 0)) throw new IllegalStateException("Cholesky 失敗 (正定値でない)");
                    L[i][j] = Math.sqrt(s);
                } else {
                    L[i][j] = s / L[j][j];
                }
            }
        }
        return L;
    }

    /** 対称正定値行列の逆行列 (Cholesky 経由) */
    static double[][] inverseSPD(double[][] a) {
        int n = a.length;
        double[][] L = cholesky(a);
        double[][] Y = new double[n][n];
        for (int c = 0; c < n; c++) {
            for (int i = 0; i < n; i++) {
                double s = (i == c) ? 1.0 : 0.0;
                for (int k = 0; k < i; k++) s -= L[i][k] * Y[k][c];
                Y[i][c] = s / L[i][i];
            }
        }
        double[][] inv = new double[n][n];
        for (int i = 0; i < n; i++) {
            for (int j = i; j < n; j++) {
                double s = 0;
                for (int k = 0; k < n; k++) s += Y[k][i] * Y[k][j];
                inv[i][j] = s;
                inv[j][i] = s;
            }
        }
        return inv;
    }

    /** 対称行列の固有値分解 (循環 Jacobi 法)。[0]=固有値, [1..n]=固有ベクトル(列) */
    static double[][] jacobiEigen(double[][] src) {
        int n = src.length;
        double[][] a = new double[n][n];
        for (int i = 0; i < n; i++) a[i] = src[i].clone();
        double[][] v = new double[n][n];
        for (int i = 0; i < n; i++) v[i][i] = 1.0;
        for (int sweep = 0; sweep < 100; sweep++) {
            double off = 0;
            for (int i = 0; i < n; i++)
                for (int j = i + 1; j < n; j++) off += a[i][j] * a[i][j];
            if (off < 1e-22) break;
            for (int p = 0; p < n - 1; p++) {
                for (int q = p + 1; q < n; q++) {
                    if (Math.abs(a[p][q]) < 1e-18) continue;
                    double theta = (a[q][q] - a[p][p]) / (2.0 * a[p][q]);
                    double t = Math.signum(theta == 0 ? 1 : theta)
                             / (Math.abs(theta) + Math.sqrt(theta * theta + 1));
                    double c = 1.0 / Math.sqrt(t * t + 1), s = t * c;
                    for (int k = 0; k < n; k++) {
                        double akp = a[k][p], akq = a[k][q];
                        a[k][p] = c * akp - s * akq;
                        a[k][q] = s * akp + c * akq;
                    }
                    for (int k = 0; k < n; k++) {
                        double apk = a[p][k], aqk = a[q][k];
                        a[p][k] = c * apk - s * aqk;
                        a[q][k] = s * apk + c * aqk;
                    }
                    for (int k = 0; k < n; k++) {
                        double vkp = v[k][p], vkq = v[k][q];
                        v[k][p] = c * vkp - s * vkq;
                        v[k][q] = s * vkp + c * vkq;
                    }
                }
            }
        }
        double[][] out = new double[n + 1][];
        double[] vals = new double[n];
        for (int i = 0; i < n; i++) vals[i] = a[i][i];
        out[0] = vals;
        for (int i = 0; i < n; i++) out[i + 1] = v[i];
        return out;
    }

    static double minEigen(double[][] m) {
        double[] w = jacobiEigen(m)[0];
        double mn = w[0];
        for (double x : w) mn = Math.min(mn, x);
        return mn;
    }

    /** Higham (2002) の交互射影で最も近い相関行列を返す。 */
    static double[][] nearestCorrelation(double[][] R, double eps) {
        int n = R.length;
        double[][] Y = new double[n][n];
        for (int i = 0; i < n; i++) Y[i] = R[i].clone();
        double[][] dS = new double[n][n];
        for (int it = 0; it < 300; it++) {
            double[][] Rk = new double[n][n];
            for (int i = 0; i < n; i++)
                for (int j = 0; j < n; j++) Rk[i][j] = Y[i][j] - dS[i][j];
            double[][] e = jacobiEigen(Rk);
            double[] w = e[0];
            double[][] X = new double[n][n];
            for (int k = 0; k < n; k++) {
                double lam = Math.max(w[k], eps);
                if (lam == 0) continue;
                for (int i = 0; i < n; i++) {
                    double vi = e[i + 1][k] * lam;
                    for (int j = i; j < n; j++) {
                        X[i][j] += vi * e[j + 1][k];
                    }
                }
            }
            for (int i = 0; i < n; i++)
                for (int j = i + 1; j < n; j++) X[j][i] = X[i][j];
            for (int i = 0; i < n; i++)
                for (int j = 0; j < n; j++) dS[i][j] = X[i][j] - Rk[i][j];
            Y = X;
            for (int i = 0; i < n; i++) Y[i][i] = 1.0;
            if (minEigen(Y) > eps * 0.5) break;
        }
        return Y;
    }

    // =====================================================================
    // サンプリング
    // =====================================================================
    /** 1次元の切断正規を逆関数法で引く (棄却なし) */
    static double truncNorm1(double mean, double sd, double lo, double hi, double u) {
        double a = (lo - mean) / sd, b = (hi - mean) / sd;
        double pa = Double.isInfinite(a) && a < 0 ? 0.0 : cdf(a);
        double pb = Double.isInfinite(b) && b > 0 ? 1.0 : cdf(b);
        double p = pa + u * (pb - pa);
        if (p < 1e-16) p = 1e-16;
        if (p > 1 - 1e-16) p = 1 - 1e-16;
        double v = mean + sd * ppf(p);
        return v < lo ? lo : (v > hi ? hi : v);
    }

    // =====================================================================
    // 設定と項目
    // =====================================================================
    static final class Item {
        String key, label, unit, group;
        int dec;
        double L, U, M, mu, sigma, d, lo, hi;
    }

    static double[] threeValues(Map<String, Object> ref, String sex) {
        if (ref.containsKey(sex)) {
            Map<String, Object> v = asMap(ref.get(sex));
            return new double[]{asD(v.get("lower")), asD(v.get("upper")), asD(v.get("median"))};
        }
        if (ref.containsKey("A")) {
            Map<String, Object> v = asMap(ref.get("A"));
            return new double[]{asD(v.get("lower")), asD(v.get("upper")), asD(v.get("median"))};
        }
        Map<String, Object> m = asMap(ref.get("M")), f = asMap(ref.get("F"));
        return new double[]{
            (asD(m.get("lower")) + asD(f.get("lower"))) / 2,
            (asD(m.get("upper")) + asD(f.get("upper"))) / 2,
            (asD(m.get("median")) + asD(f.get("median"))) / 2};
    }

    // =====================================================================
    // main
    // =====================================================================
    public static void main(String[] args) throws IOException {
        // --- 既定値 (Python 版と揃える) ---
        int n = 1, points = 12, seed = 20260821, relax = 20;
        double interval = 4.0, sfrac = 0.10, noise = 0.01;
        String mode = "series", sex = "M", trunc = "sd5", out = "-", itemsArg = "", fmt = "csv";
        boolean listItems = false, explain = false, allItems = false;
        boolean showParams = false, deviationView = false, noComment = false;

        Path base = Paths.get(System.getProperty("user.dir"));
        String catalogPath = null, corrPath = null;

        for (int i = 0; i < args.length; i++) {
            String a = args[i];
            switch (a) {
                case "--n":           n = Integer.parseInt(args[++i]); break;
                case "--mode":        mode = args[++i]; break;
                case "--points":      points = Integer.parseInt(args[++i]); break;
                case "--interval":    interval = Double.parseDouble(args[++i]); break;
                case "--sfrac":       sfrac = Double.parseDouble(args[++i]); break;
                case "--noise":       noise = Double.parseDouble(args[++i]); break;
                case "--sex":         sex = args[++i]; break;
                case "--items":       itemsArg = args[++i]; break;
                case "--all-items":   allItems = true; break;
                case "--trunc":       trunc = args[++i]; break;
                case "--seed":        seed = Integer.parseInt(args[++i]); break;
                case "--relax":       relax = Integer.parseInt(args[++i]); break;
                case "--catalog":     catalogPath = args[++i]; break;
                case "--correlation": corrPath = args[++i]; break;
                case "-o": case "--out": out = args[++i]; break;
                case "--format":      fmt = args[++i]; break;
                case "--list-items":  listItems = true; break;
                case "--explain":     explain = true; break;
                case "--show-params": showParams = true; break;
                case "--deviation":   deviationView = true; break;
                case "--no-comment":  noComment = true; break;
                case "-h": case "--help": usage(); return;
                default:
                    System.err.println("不明な引数: " + a + "  (--help)");
                    System.exit(1);
            }
        }

        if (catalogPath == null) catalogPath = findData(base, "catalog.json");
        if (corrPath == null)    corrPath = findData(base, "correlation.json");

        Map<String, Object> cat = asMap(Json.parse(
            new String(Files.readAllBytes(Paths.get(catalogPath)), StandardCharsets.UTF_8)));
        Map<String, Object> corr = asMap(Json.parse(
            new String(Files.readAllBytes(Paths.get(corrPath)), StandardCharsets.UTF_8)));

        List<Object> catItems = asList(cat.get("items"));
        Map<String, Map<String, Object>> byKey = new LinkedHashMap<>();
        for (Object o : catItems) {
            Map<String, Object> it = asMap(o);
            byKey.put((String) it.get("key"), it);
        }

        if (explain) {
            usage();
            System.out.println();
            System.out.println("カタログ      : " + catalogPath);
            System.out.println("  項目数      : " + catItems.size());
            System.out.println("  既定の項目  : " + asList(cat.get("defaults")).size());
            System.out.println("  出所        : " + cat.get("_source"));
            System.out.println("相関定義      : " + corrPath);
            System.out.println("  エッジ数    : " + asList(corr.get("edges")).size());
            System.out.println("  固有値の床  : " + corr.get("eigen_floor"));
            System.out.println("  出所        : " + corr.get("_source"));
            return;
        }

        if (listItems) {
            System.out.printf("%-8s%-16s%3s  %-10s%-26s%s%n",
                "key", "単位", "桁", "グループ", "基準範囲", "説明");
            for (Object o : catItems) {
                Map<String, Object> it = asMap(o);
                Map<String, Object> ref = asMap(it.get("reference"));
                StringBuilder r = new StringBuilder();
                for (Map.Entry<String, Object> e : ref.entrySet()) {
                    Map<String, Object> v = asMap(e.getValue());
                    if (r.length() > 0) r.append(" / ");
                    r.append(e.getKey()).append(' ').append(trimNum(asD(v.get("lower"))))
                     .append('-').append(trimNum(asD(v.get("upper"))));
                }
                System.out.printf("%-8s%-16s%3d  %-10s%-26s%s%n",
                    it.get("key"), it.get("unit"), (int) asD(it.get("decimals")),
                    it.get("group"), r, it.get("label"));
            }
            System.out.println("\n全 " + catItems.size() + " 項目。既定は "
                + asList(cat.get("defaults")).size() + " 項目。");
            return;
        }

        // --- 項目の決定 ---
        List<String> keys = new ArrayList<>();
        if (allItems) {
            keys.addAll(byKey.keySet());
        } else if (!itemsArg.trim().isEmpty()) {
            for (String k : itemsArg.split(",")) {
                k = k.trim();
                if (k.isEmpty()) continue;
                if (!byKey.containsKey(k)) {
                    System.err.println("不明な検査項目: " + k + "  (--list-items で一覧)");
                    System.exit(1);
                }
                keys.add(k);
            }
        } else {
            for (Object o : asList(cat.get("defaults"))) keys.add((String) o);
        }
        if (keys.isEmpty() || n < 1) {
            System.err.println("検査項目が空、または --n が 1 未満です");
            System.exit(1);
        }

        double z975 = cat.containsKey("_z975") ? asD(cat.get("_z975")) : 1.959963985;
        int pts = mode.equals("series") ? points : 1;

        // --- 性別グループ ---
        List<String> gSex = new ArrayList<>();
        List<Integer> gCnt = new ArrayList<>();
        if (sex.equals("M") || sex.equals("F")) {
            gSex.add(sex); gCnt.add(n);
        } else {
            int m = (n + 1) / 2, f = n - m;
            if (m > 0) { gSex.add("M"); gCnt.add(m); }
            if (f > 0) { gSex.add("F"); gCnt.add(f); }
        }

        PrintWriter w = out.equals("-")
            ? new PrintWriter(new java.io.OutputStreamWriter(System.out, StandardCharsets.UTF_8))
            : new PrintWriter(new BufferedWriter(new FileWriter(out, StandardCharsets.UTF_8)));

        List<Item> meta0 = null;
        List<String[]> rows = new ArrayList<>();
        boolean adjusted = false;
        int pid = 0;
        double[][] lastVals = null;
        List<Item> lastMeta = null;

        for (int gi = 0; gi < gSex.size(); gi++) {
            String gs = gSex.get(gi);
            int cnt = gCnt.get(gi);
            int k = keys.size();

            // --- パラメータの復元 ---
            List<Item> items = new ArrayList<>();
            for (String key : keys) {
                Map<String, Object> src = byKey.get(key);
                double[] luM = threeValues(asMap(src.get("reference")), gs);
                double[] p = lnorm3FromThree(luM[0], luM[1], luM[2], z975);
                Item it = new Item();
                it.key = key;
                it.label = (String) src.get("label");
                it.unit = (String) src.get("unit");
                it.group = (String) src.get("group");
                it.dec = (int) asD(src.get("decimals"));
                it.L = luM[0]; it.U = luM[1]; it.M = luM[2];
                it.mu = p[0]; it.sigma = p[1]; it.d = p[2];
                if (trunc.equals("sd5")) {
                    it.lo = it.mu - 5 * it.sigma;
                    it.hi = it.mu + 5 * it.sigma;
                } else if (trunc.equals("ri")) {
                    it.lo = Math.log(it.L - it.d);
                    it.hi = Math.log(it.U - it.d);
                } else {
                    it.lo = Double.NEGATIVE_INFINITY;
                    it.hi = Double.POSITIVE_INFINITY;
                }
                if (it.d < 0) it.lo = Math.max(it.lo, Math.log(-it.d));  // 検査値は負にならない
                items.add(it);
            }
            if (meta0 == null) meta0 = items;

            // --- 相関行列 ---
            double[][] R = new double[k][k];
            for (int i = 0; i < k; i++) R[i][i] = 1.0;
            Map<String, Integer> pos = new LinkedHashMap<>();
            for (int i = 0; i < k; i++) pos.put(keys.get(i), i);
            // edges は男女共通の基本エッジ。edges_M / edges_F があれば、
            // その性別では同じペアを上書きする。赤血球系の相関は論文2 式4 の
            // 恒等関係から男女別の基準範囲だけを入力に導出でき、男女で実際に異なる。
            // (導出: tools/derive-sex-corr.php)
            List<String> edgeKeys = new ArrayList<>();
            edgeKeys.add("edges");
            if (gs.equals("M") || gs.equals("F")) edgeKeys.add("edges_" + gs);
            for (String ek : edgeKeys) {
                Object raw = corr.get(ek);
                if (raw == null) continue;
                for (Object o : asList(raw)) {
                    Map<String, Object> e = asMap(o);
                    Integer ia = pos.get((String) e.get("a")), ib = pos.get((String) e.get("b"));
                    if (ia != null && ib != null) {
                        double r = asD(e.get("r"));
                        R[ia][ib] = r; R[ib][ia] = r;
                    }
                }
            }
            double floor = corr.containsKey("eigen_floor") ? asD(corr.get("eigen_floor")) : 0.02;
            if (minEigen(R) < floor) { R = nearestCorrelation(R, floor); adjusted = true; }

            double[][] Sigma = new double[k][k];
            for (int i = 0; i < k; i++)
                for (int j = 0; j < k; j++)
                    Sigma[i][j] = R[i][j] * items.get(i).sigma * items.get(j).sigma;

            double[][] L = cholesky(Sigma);
            double[][] Q = inverseSPD(Sigma);
            double[] qd = new double[k];
            for (int i = 0; i < k; i++) qd[i] = 1.0 / Math.sqrt(Q[i][i]);
            double[] mu = new double[k], lo = new double[k], hi = new double[k];
            for (int i = 0; i < k; i++) {
                mu[i] = items.get(i).mu; lo[i] = items.get(i).lo; hi[i] = items.get(i).hi;
            }
            boolean finite = false;
            for (int i = 0; i < k; i++)
                if (!Double.isInfinite(lo[i]) || !Double.isInfinite(hi[i])) finite = true;

            Random rng = new Random(seed + 7919L * gi);
            double[][] vals = new double[cnt * pts][k];
            int row = 0;

            for (int p = 0; p < cnt; p++) {
                pid++;
                String id = String.format("SL%06d", pid);
                if (mode.equals("cross")) {
                    double[] z = drawMVN(mu, L, rng);
                    // 箱の内側に落ちた点は棄却法が受理した標本そのものなので厳密に使える
                    if (finite && !inside(z, lo, hi)) {
                        for (int i = 0; i < k; i++) z[i] = Math.min(Math.max(z[i], lo[i]), hi[i]);
                        double[] r = matVec(Q, sub(z, mu));
                        int[] order = new int[k];
                        for (int i = 0; i < k; i++) order[i] = i;
                        for (int t = 0; t < relax; t++) {
                            shuffle(order, rng);
                            gibbsSweep(z, Q, qd, lo, hi, r, order, rng);
                        }
                    }
                    String[] rec = new String[2 + k];
                    rec[0] = id; rec[1] = gs;
                    for (int i = 0; i < k; i++) {
                        Item it = items.get(i);
                        double y = round(Math.exp(z[i]) + it.d, it.dec);
                        vals[row][i] = y;
                        rec[2 + i] = fmtNum(y, it.dec);
                    }
                    rows.add(rec);
                    row++;
                } else {
                    double[] z0 = drawMVN(mu, L, rng);
                    for (int i = 0; i < k; i++) z0[i] = Math.min(Math.max(z0[i], lo[i]), hi[i]);
                    for (int t = 0; t < pts; t++) {
                        double[] z = new double[k];
                        double[] wv = new double[k];
                        for (int i = 0; i < k; i++) wv[i] = rng.nextGaussian();
                        for (int i = 0; i < k; i++) {
                            double acc = 0;
                            for (int j = 0; j <= i; j++) acc += L[i][j] * wv[j];
                            z[i] = z0[i] + sfrac * acc;
                            if (noise > 0) {
                                z[i] += noise * Math.sqrt(Sigma[i][i]) * rng.nextGaussian();
                            }
                            z[i] = Math.min(Math.max(z[i], lo[i]), hi[i]);
                        }
                        String[] rec = new String[4 + k];
                        rec[0] = id; rec[1] = gs;
                        rec[2] = String.valueOf(t + 1);
                        rec[3] = String.format("%.2f", t * interval);
                        for (int i = 0; i < k; i++) {
                            Item it = items.get(i);
                            double y = round(Math.exp(z[i]) + it.d, it.dec);
                            vals[row][i] = y;
                            rec[4 + i] = fmtNum(y, it.dec);
                        }
                        rows.add(rec);
                        row++;
                    }
                }
            }
            lastVals = vals;
            lastMeta = items;
        }

        // --- 出力 ---
        if (!noComment && fmt.equals("csv")) {
            w.println("# SynthLab synthetic laboratory values -- NOT real patient data");
            w.println("# 架空の数値です。実在の患者データではなく、臨床判断には使用できません。");
            w.println("# 生成器: lnorm3 (3パラメータ対数正規) + 切断多変量正規サンプリング");
            w.println("# 入力(A) 条件: mode=" + mode + " n=" + n + " points=" + pts
                + " sex=" + sex + " trunc=" + trunc + " seed=" + seed
                + (mode.equals("series")
                    ? " sfrac=" + sfrac + " noise=" + noise + " interval=" + interval : ""));
            w.println("# 入力(B) カタログ: " + Paths.get(catalogPath).getFileName()
                + " / 相関: " + Paths.get(corrPath).getFileName()
                + (adjusted ? "  (相関行列は Higham 射影で正定値化した)" : ""));
            w.println("# 同じ seed と同じ入力ファイルなら同じデータが再現されます。");
            w.println("# item,unit,mu,sigma,d,ref_lower,ref_upper");
            for (Item it : meta0) {
                w.printf("# %s,%s,%.8f,%.8f,%.8f,%s,%s%n",
                    it.key, it.unit, it.mu, it.sigma, it.d, trimNum(it.L), trimNum(it.U));
            }
        }
        if (fmt.equals("json")) {
            w.println("{\"meta\":{\"mode\":\"" + mode + "\",\"n\":" + n + ",\"points\":" + pts
                + ",\"sex\":\"" + sex + "\",\"seed\":" + seed + "},\"rows\":[");
            for (int i = 0; i < rows.size(); i++) {
                String[] r = rows.get(i);
                StringBuilder b = new StringBuilder(" {\"patient_id\":\"" + r[0]
                    + "\",\"sex\":\"" + r[1] + "\"");
                int off = mode.equals("series") ? 4 : 2;
                if (mode.equals("series")) {
                    b.append(",\"visit\":").append(r[2]).append(",\"week\":").append(r[3]);
                }
                for (int j = 0; j < meta0.size(); j++) {
                    b.append(",\"").append(meta0.get(j).key).append("\":").append(r[off + j]);
                }
                b.append('}').append(i < rows.size() - 1 ? "," : "");
                w.println(b);
            }
            w.println("]}");
        } else {
            StringBuilder h = new StringBuilder("patient_id,sex");
            if (mode.equals("series")) h.append(",visit,week");
            for (Item it : meta0) h.append(',').append(it.key);
            w.println(h);
            for (String[] r : rows) w.println(String.join(",", r));
        }
        w.flush();
        if (!out.equals("-")) w.close();

        if (showParams) {
            System.err.printf("%n%-8s%10s%10s%12s%20s%n", "項目", "mu", "sigma", "d", "基準範囲");
            for (Item it : meta0) {
                System.err.printf("%-8s%10.4f%10.4f%12.4f%10s-%-9s%n",
                    it.key, it.mu, it.sigma, it.d, trimNum(it.L), trimNum(it.U));
            }
        }
        if (deviationView && lastVals != null) {
            System.err.printf("%n個人票 SL000001 (%s)%n", gSex.get(0));
            System.err.printf("%-8s%10s%18s%9s%9s  判定%n",
                "項目", "値", "基準範囲", "偏差値", "百分位");
            for (int j = 0; j < lastMeta.size(); j++) {
                Item it = lastMeta.get(j);
                double v = 0;
                int cnt = mode.equals("series") ? pts : 1;
                for (int t = 0; t < cnt; t++) v += lastVals[t][j];
                v /= cnt;
                double T = deviation(v, it.mu, it.sigma, it.d);
                double pc = cdf((Math.log(v - it.d) - it.mu) / it.sigma);
                String pcs = pc > 0.9999 ? ">99.99%"
                           : (pc < 0.0001 ? "<0.01%" : String.format("%.1f%%", pc * 100));
                boolean in = v >= it.L && v <= it.U;
                System.err.printf("%-8s%10s%18s%9.1f%9s  %s%n", it.key, fmtNum(v, it.dec),
                    trimNum(it.L) + "-" + trimNum(it.U), T, pcs,
                    in ? "基準範囲内" : (v > it.U ? "高値" : "低値"));
            }
            System.err.println("\n偏差値 T = 50 + 10 (log(x-d) - mu)/sigma。"
                + "基準範囲の下限が約30.4、中央値が50、上限が約69.6。");
        }
    }

    // ---------------------------------------------------------------------
    static boolean inside(double[] z, double[] lo, double[] hi) {
        for (int i = 0; i < z.length; i++) if (z[i] < lo[i] || z[i] > hi[i]) return false;
        return true;
    }

    static double[] drawMVN(double[] mu, double[][] L, Random rng) {
        int k = mu.length;
        double[] w = new double[k], z = new double[k];
        for (int i = 0; i < k; i++) w[i] = rng.nextGaussian();
        for (int i = 0; i < k; i++) {
            double acc = 0;
            for (int j = 0; j <= i; j++) acc += L[i][j] * w[j];
            z[i] = mu[i] + acc;
        }
        return z;
    }

    static double[] sub(double[] a, double[] b) {
        double[] o = new double[a.length];
        for (int i = 0; i < a.length; i++) o[i] = a[i] - b[i];
        return o;
    }

    static double[] matVec(double[][] m, double[] v) {
        double[] o = new double[v.length];
        for (int i = 0; i < v.length; i++) {
            double s = 0;
            for (int j = 0; j < v.length; j++) s += m[i][j] * v[j];
            o[i] = s;
        }
        return o;
    }

    static void shuffle(int[] a, Random rng) {
        for (int i = a.length - 1; i > 0; i--) {
            int j = rng.nextInt(i + 1);
            int t = a[i]; a[i] = a[j]; a[j] = t;
        }
    }

    /** 切断多変量正規に対する Gibbs を1掃引 */
    static void gibbsSweep(double[] z, double[][] Q, double[] qd, double[] lo, double[] hi,
                           double[] r, int[] order, Random rng) {
        for (int idx = 0; idx < order.length; idx++) {
            int i = order[idx];
            double sdc = qd[i];
            double m = z[i] - r[i] * sdc * sdc;
            double nv = truncNorm1(m, sdc, lo[i], hi[i], rng.nextDouble());
            double delta = nv - z[i];
            z[i] = nv;
            for (int j = 0; j < z.length; j++) r[j] += delta * Q[j][i];
        }
    }

    static double round(double v, int dec) {
        double p = Math.pow(10, dec);
        return Math.round(v * p) / p;
    }

    static String fmtNum(double v, int dec) {
        return String.format("%." + dec + "f", v);
    }

    /** 1.0 を "1" のように出す (カタログの表示用) */
    static String trimNum(double v) {
        if (v == Math.rint(v) && Math.abs(v) < 1e15) return String.valueOf((long) v);
        return String.valueOf(v);
    }

    static String findData(Path base, String name) {
        Path[] cands = {
            base.resolve("data").resolve(name),
            base.resolve("..").resolve("data").resolve(name),
            base.resolve("..").resolve("..").resolve("data").resolve(name),
            Paths.get("/home/sanuki/synthlab/data").resolve(name),
        };
        for (Path p : cands) if (Files.exists(p)) return p.normalize().toString();
        return base.resolve("data").resolve(name).toString();
    }

    static void usage() {
        System.out.println(String.join("\n",
            "SynthLab CLI (Java) — 擬似血液検査値ジェネレータ",
            "",
            "入力について:",
            "  実データは入力しない (できない)。入力は次の2つだけ。",
            "    (A) 生成条件      … 下のコマンドライン引数",
            "    (B) パラメータ源  … --catalog / --correlation の JSON ファイル",
            "  基準範囲の3つの値 (下限・上限・中央値) から分布を閉じた形で復元し、",
            "  そこから乱数を引く。実際の患者データは要らないし、渡す口もない。",
            "",
            "(A) 生成条件",
            "  --n N              患者数 (既定 1)",
            "  --mode series|cross  時系列 (既定) / 断面",
            "  --points N         受診回数 (series, 既定 12)",
            "  --interval W       受診間隔 (週, 既定 4)",
            "  --sfrac F          個人内変動の幅。sigma に対する比 (既定 0.10)",
            "  --noise F          測定ノイズ (既定 0.01)",
            "  --sex M|F|A        M=男性 F=女性 A=男女混合 50:50 (既定 M)",
            "  --items a,b,c      検査項目。空ならカタログの既定一式",
            "  --all-items        カタログの全項目",
            "  --trunc sd5|ri|none  値域の切断 (既定 sd5 = ±5σ)",
            "  --seed N           乱数種 (既定 20260821)",
            "  --relax N          断面モードの緩和掃引数 (既定 20)",
            "",
            "(B) パラメータ源",
            "  --catalog PATH     検査項目カタログ JSON (既定 data/catalog.json)",
            "  --correlation PATH 相関定義 JSON (既定 data/correlation.json)",
            "",
            "出力",
            "  -o, --out PATH     出力先 (既定 - = 標準出力)",
            "  --format csv|json  出力形式 (既定 csv)",
            "  --deviation        偏差値の個人票を標準エラーへ",
            "  --show-params      復元した (mu, sigma, d) を標準エラーへ",
            "  --no-comment       CSV の # コメント行を出さない",
            "",
            "情報",
            "  --list-items       使える検査項目の一覧",
            "  --explain          入力と手法の説明",
            "  -h, --help         この画面"));
    }
}
