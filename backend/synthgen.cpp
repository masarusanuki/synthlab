// synthgen.cpp -- SynthLab pseudo laboratory-test-value generator (backend)
//
// 論文の手法をそのまま実装した依存関係ゼロのサンプラ。
//   [P1] Shiro & Kagawa, MEDINFO 2022, doi:10.3233/SHTI220270
//        3パラメータ対数正規分布 lnorm3(x; mu, sigma, d) が血液検査値に最適。
//   [P2] 城 真範, 生物試料分析 47(4), 2024
//        lnorm3 を仮定した同時分布 (分散共分散行列 + Graphical LASSO) の構成。
//   [P3] Furukawa, Kagawa, Sanuki & Shiro, MEDINFO 2025, doi:10.3233/SHTI251248
//        Cholesky 分解 + 逆関数法で時系列を高速合成する方法。
//   [C]  code/jamia26a.cpp
//        切断多変量正規分布に対する Gibbs サンプラ (精度行列を用いた条件付き分布)。
//
// ビルド: g++ -std=c++17 -O2 synthgen.cpp -o synthgen
//
// 入力は標準入力の key/value テキスト。出力は標準出力の CSV。
// 使い方の詳細は printusage() を参照。

#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <iostream>
#include <limits>
#include <random>
#include <sstream>
#include <string>
#include <vector>

// -----------------------------------------------------------------------------
// 小さな行列型 (行優先の密行列)
// -----------------------------------------------------------------------------

// 正方行列を保持する構造体
struct Mat {
 int n;
 std::vector<double> a;

 // 行列を確保する関数
 void init(int dim)
 {
  n = dim;
  a.assign((std::size_t)dim * (std::size_t)dim, 0.0);
 }

 // 要素を参照する関数
 double &at(int i, int j)
 {
  return a[(std::size_t)i * (std::size_t)n + (std::size_t)j];
 }

 // 要素を読む関数
 double get(int i, int j) const
 {
  return a[(std::size_t)i * (std::size_t)n + (std::size_t)j];
 }
};

// 実行時設定を保持する構造体
struct Conf {
 unsigned long long seed;
 int npat;            // 患者数
 int dim;             // 検査項目数
 std::string mode;    // "cross" または "series"
 int burnin;          // Gibbs のバーンイン回数
 int thin;            // Gibbs の間引き
 int relax;          // 患者ごとの緩和掃引数 (cross)
 bool randomscan;     // Gibbs の走査順をランダム化するか
 int points;          // 時系列の点数
 double sfrac;        // 時系列の変動幅 (sigma に対する比)
 double noise;        // 連結時に加える独立ノイズ (sigma に対する比)
 double interval;     // 検査間隔 (週)
 double jitter;       // Cholesky 失敗時の初期ジッタ
 int maxjit;          // ジッタ試行回数
 int digits;          // 出力桁数
 bool writez;         // 対数スケールの値も出すか
 bool writediag;      // 診断情報を stderr に出すか
};

// 検査項目を保持する構造体
struct Item {
 std::string name;
 std::string unit;
 double mu;      // 対数スケールの平均
 double sd;      // 対数スケールの標準偏差
 double shift;   // d (絶対値方向のシフト)
 double lo;      // 対数スケールの下限 (log(y-d) の下限)
 double hi;      // 対数スケールの上限
 double decim;   // 出力の小数桁数
};

static const double INFPOS = std::numeric_limits<double>::infinity();

// -----------------------------------------------------------------------------
// 文字列ユーティリティ
// -----------------------------------------------------------------------------

// 文字列の前後空白を削除する関数
static std::string trim(const std::string &s)
{
 std::size_t a = 0;
 while (a < s.size() && std::isspace((unsigned char)s[a])) {
  a++;
 }
 std::size_t b = s.size();
 while (b > a && std::isspace((unsigned char)s[b - 1])) {
  b--;
 }
 return s.substr(a, b - a);
}

// 実数文字列を読む関数 (inf を許容する)
static bool parsed(const std::string &in, double &v)
{
 std::string s = trim(in);
 for (std::size_t i = 0; i < s.size(); i++) {
  s[i] = (char)std::tolower((unsigned char)s[i]);
 }
 if (s == "inf" || s == "+inf" || s == "infinity") {
  v = INFPOS;
  return true;
 }
 if (s == "-inf" || s == "-infinity") {
  v = -INFPOS;
  return true;
 }
 char *e = 0;
 v = std::strtod(s.c_str(), &e);
 if (e == s.c_str()) {
  return false;
 }
 while (*e != 0) {
  if (!std::isspace((unsigned char)*e)) {
   return false;
  }
  e++;
 }
 return std::isfinite(v) || std::isinf(v);
}

// 整数文字列を読む関数
static bool parsei(const std::string &in, int &v)
{
 std::string s = trim(in);
 char *e = 0;
 long x = std::strtol(s.c_str(), &e, 10);
 if (e == s.c_str()) {
  return false;
 }
 v = (int)x;
 return true;
}

// -----------------------------------------------------------------------------
// 正規分布関連の関数群 (jamia26a.cpp と同一の方針)
// -----------------------------------------------------------------------------

// 標準正規CDFを計算する関数
static double normcdf(double x)
{
 return 0.5 * std::erfc(-x / std::sqrt(2.0));
}

// 標準正規右裾確率を計算する関数
static double normsf(double x)
{
 return 0.5 * std::erfc(x / std::sqrt(2.0));
}

// log(exp(a)+exp(b))を計算する関数
static double logsum(double a, double b)
{
 if (a == -INFPOS) {
  return b;
 }
 if (b == -INFPOS) {
  return a;
 }
 if (a < b) {
  std::swap(a, b);
 }
 return a + std::log1p(std::exp(b - a));
}

// log(exp(a)-exp(b))を計算する関数
static double logdiff(double a, double b)
{
 if (b == -INFPOS) {
  return a;
 }
 if (!(a > b)) {
  return -INFPOS;
 }
 return a + std::log1p(-std::exp(b - a));
}

// 標準正規右裾確率の対数を計算する関数 (裾では漸近展開を使う)
static double lognormsf(double x)
{
 if (x < 8.0) {
  double p = normsf(x);
  if (p <= 0.0) {
   return -INFPOS;
  }
  return std::log(p);
 }
 double pi = std::acos(-1.0);
 double xx = x * x;
 double corr = 1.0 - 1.0 / xx + 3.0 / (xx * xx);
 if (corr <= 0.0) {
  corr = 1.0;
 }
 return -0.5 * xx - std::log(x) - 0.5 * std::log(2.0 * pi) + std::log(corr);
}

// log右裾確率から標準正規分位点を求める関数 (二分法)
static double invlogsf(double logq)
{
 double lo = 0.0;
 double hi = 8.0;
 while (lognormsf(hi) > logq && hi < 1.0e6) {
  hi *= 2.0;
 }
 for (int i = 0; i < 200; i++) {
  double mid = 0.5 * (lo + hi);
  if (lognormsf(mid) > logq) {
   lo = mid;
  } else {
   hi = mid;
  }
 }
 return 0.5 * (lo + hi);
}

// Acklam型近似で標準正規分位点を求める関数
static double norminv(double p)
{
 double eps = 1.0e-16;
 if (p <= eps) {
  p = eps;
 }
 if (p >= 1.0 - eps) {
  p = 1.0 - eps;
 }
 const double a1 = -3.969683028665376e+01;
 const double a2 = 2.209460984245205e+02;
 const double a3 = -2.759285104469687e+02;
 const double a4 = 1.383577518672690e+02;
 const double a5 = -3.066479806614716e+01;
 const double a6 = 2.506628277459239e+00;
 const double b1 = -5.447609879822406e+01;
 const double b2 = 1.615858368580409e+02;
 const double b3 = -1.556989798598866e+02;
 const double b4 = 6.680131188771972e+01;
 const double b5 = -1.328068155288572e+01;
 const double c1 = -7.784894002430293e-03;
 const double c2 = -3.223964580411365e-01;
 const double c3 = -2.400758277161838e+00;
 const double c4 = -2.549732539343734e+00;
 const double c5 = 4.374664141464968e+00;
 const double c6 = 2.938163982698783e+00;
 const double d1 = 7.784695709041462e-03;
 const double d2 = 3.224671290700398e-01;
 const double d3 = 2.445134137142996e+00;
 const double d4 = 3.754408661907416e+00;
 double plow = 0.02425;
 double phigh = 1.0 - plow;
 double q = 0.0;
 double r = 0.0;
 double x = 0.0;
 if (p < plow) {
  q = std::sqrt(-2.0 * std::log(p));
  x = (((((c1 * q + c2) * q + c3) * q + c4) * q + c5) * q + c6) /
      ((((d1 * q + d2) * q + d3) * q + d4) * q + 1.0);
  return x;
 }
 if (p > phigh) {
  q = std::sqrt(-2.0 * std::log(1.0 - p));
  x = -(((((c1 * q + c2) * q + c3) * q + c4) * q + c5) * q + c6) /
      ((((d1 * q + d2) * q + d3) * q + d4) * q + 1.0);
  return x;
 }
 q = p - 0.5;
 r = q * q;
 x = (((((a1 * r + a2) * r + a3) * r + a4) * r + a5) * r + a6) * q /
     (((((b1 * r + b2) * r + b3) * r + b4) * r + b5) * r + 1.0);
 return x;
}

// 開区間一様乱数を生成する関数
static double randunit(std::mt19937_64 &rng)
{
 std::uniform_real_distribution<double> uni(0.0, 1.0);
 double u = uni(rng);
 if (u <= 0.0) {
  u = std::numeric_limits<double>::min();
 }
 if (u >= 1.0) {
  u = 1.0 - std::numeric_limits<double>::epsilon();
 }
 return u;
}

// 標準切断正規乱数を逆関数法で生成する関数
static bool sampletruncstd(double alpha, double beta, std::mt19937_64 &rng, double &z)
{
 if (!(alpha < beta)) {
  return false;
 }
 double u = randunit(rng);
 if (std::isinf(alpha) && alpha < 0.0 && std::isinf(beta) && beta > 0.0) {
  z = norminv(u);
  return true;
 }
 if (beta < -8.0) {
  // 左裾は対称性を使って右裾の問題に変換する
  double y = 0.0;
  bool ok = sampletruncstd(-beta, -alpha, rng, y);
  z = -y;
  return ok;
 }
 if (alpha > 8.0) {
  // 右裾は対数スケールで計算し桁落ちを避ける
  double la = lognormsf(alpha);
  double lb = -INFPOS;
  if (std::isfinite(beta)) {
   lb = lognormsf(beta);
  }
  double ld = logdiff(la, lb);
  double lq = logsum(lb, std::log(u) + ld);
  z = invlogsf(lq);
  if (z < alpha) {
   z = alpha;
  }
  if (z > beta) {
   z = beta;
  }
  return true;
 }
 double pa = 0.0;
 double pb = 1.0;
 if (std::isfinite(alpha)) {
  pa = normcdf(alpha);
 }
 if (std::isfinite(beta)) {
  pb = normcdf(beta);
 }
 double p = pa + u * (pb - pa);
 z = norminv(p);
 if (z < alpha) {
  z = alpha;
 }
 if (z > beta) {
  z = beta;
 }
 return true;
}

// 1次元切断正規乱数を生成する関数
static bool sampletrunc(double mean, double sd, double low, double high,
                        std::mt19937_64 &rng, double &x)
{
 if (!(sd > 0.0)) {
  return false;
 }
 double alpha = (low - mean) / sd;
 double beta = (high - mean) / sd;
 double z = 0.0;
 if (!sampletruncstd(alpha, beta, rng, z)) {
  return false;
 }
 x = mean + sd * z;
 if (x < low) {
  x = low;
 }
 if (x > high) {
  x = high;
 }
 return true;
}

// -----------------------------------------------------------------------------
// 線形代数 (Cholesky と精度行列)
// -----------------------------------------------------------------------------

// 行列を対称化する関数
static void symmetrize(Mat &m)
{
 for (int i = 0; i < m.n; i++) {
  for (int j = i + 1; j < m.n; j++) {
   double v = 0.5 * (m.get(i, j) + m.get(j, i));
   m.at(i, j) = v;
   m.at(j, i) = v;
  }
 }
}

// Cholesky分解 L L^T = A を計算する関数 (Lは下三角)
static bool cholesky(const Mat &A, Mat &L)
{
 int n = A.n;
 L.init(n);
 for (int i = 0; i < n; i++) {
  for (int j = 0; j <= i; j++) {
   double s = A.get(i, j);
   for (int k = 0; k < j; k++) {
    s -= L.get(i, k) * L.get(j, k);
   }
   if (i == j) {
    if (!(s > 0.0)) {
     return false;
    }
    L.at(i, j) = std::sqrt(s);
   } else {
    L.at(i, j) = s / L.get(j, j);
   }
  }
 }
 return true;
}

// Cholesky因子から逆行列 (精度行列) を求める関数
static void cholinv(const Mat &L, Mat &inv)
{
 int n = L.n;
 // まず L Y = I を前進代入で解いて Y = L^{-1} を得る
 Mat Y;
 Y.init(n);
 for (int c = 0; c < n; c++) {
  for (int i = 0; i < n; i++) {
   double s = (i == c) ? 1.0 : 0.0;
   for (int k = 0; k < i; k++) {
    s -= L.get(i, k) * Y.get(k, c);
   }
   Y.at(i, c) = s / L.get(i, i);
  }
 }
 // A^{-1} = (L L^T)^{-1} = L^{-T} L^{-1} = Y^T Y
 inv.init(n);
 for (int i = 0; i < n; i++) {
  for (int j = i; j < n; j++) {
   double s = 0.0;
   for (int k = 0; k < n; k++) {
    s += Y.get(k, i) * Y.get(k, j);
   }
   inv.at(i, j) = s;
   inv.at(j, i) = s;
  }
 }
}

// ジッタを加えつつCholesky分解を成功させる関数
static bool robustchol(Mat A, Mat &L, double jit0, int maxjit, double &usedjit, std::string &err)
{
 symmetrize(A);
 usedjit = 0.0;
 if (cholesky(A, L)) {
  return true;
 }
 double jit = jit0;
 if (!(jit > 0.0)) {
  jit = 1.0e-10;
 }
 for (int t = 0; t < maxjit; t++) {
  Mat B = A;
  for (int i = 0; i < B.n; i++) {
   B.at(i, i) += jit;
  }
  if (cholesky(B, L)) {
   usedjit = jit;
   return true;
  }
  jit *= 10.0;
 }
 err = "Cholesky factorization failed even with jitter";
 return false;
}

// -----------------------------------------------------------------------------
// 入力の読み込み
// -----------------------------------------------------------------------------

// 既定の設定を作る関数
static Conf defconf()
{
 Conf c;
 c.seed = 20260821ULL;
 c.npat = 100;
 c.dim = 0;
 c.mode = "cross";
 c.burnin = 200;
 c.thin = 1;
 c.relax = 20;
 c.randomscan = true;
 c.points = 12;
 c.sfrac = 0.10;
 c.noise = 0.01;
 c.interval = 4.0;
 c.jitter = 1.0e-10;
 c.maxjit = 8;
 c.digits = 6;
 c.writez = false;
 c.writediag = false;
 return c;
}

// 標準入力の仕様を読む関数
static bool readspec(std::istream &in, Conf &conf, std::vector<Item> &items, Mat &corr,
                     std::string &err)
{
 std::vector<std::vector<double> > rows;  // corr 行
 std::string line;
 int lineno = 0;
 while (std::getline(in, line)) {
  lineno++;
  std::string s = trim(line);
  if (s.size() == 0 || s[0] == '#') {
   continue;
  }
  std::istringstream ss(s);
  std::string key;
  ss >> key;
  if (key == "seed") {
   double v = 0.0;
   std::string rest;
   ss >> rest;
   if (!parsed(rest, v)) {
    err = "bad seed";
    return false;
   }
   conf.seed = (unsigned long long)(v < 0 ? -v : v);
  } else if (key == "npat") {
   std::string rest;
   ss >> rest;
   if (!parsei(rest, conf.npat)) {
    err = "bad npat";
    return false;
   }
  } else if (key == "mode") {
   ss >> conf.mode;
  } else if (key == "burnin") {
   std::string rest;
   ss >> rest;
   parsei(rest, conf.burnin);
  } else if (key == "thin") {
   std::string rest;
   ss >> rest;
   parsei(rest, conf.thin);
  } else if (key == "relax") {
   std::string rest;
   ss >> rest;
   parsei(rest, conf.relax);
  } else if (key == "randomscan") {
   std::string rest;
   ss >> rest;
   conf.randomscan = (rest == "1" || rest == "true" || rest == "yes");
  } else if (key == "points") {
   std::string rest;
   ss >> rest;
   parsei(rest, conf.points);
  } else if (key == "sfrac") {
   std::string rest;
   ss >> rest;
   parsed(rest, conf.sfrac);
  } else if (key == "noise") {
   std::string rest;
   ss >> rest;
   parsed(rest, conf.noise);
  } else if (key == "interval") {
   std::string rest;
   ss >> rest;
   parsed(rest, conf.interval);
  } else if (key == "jitter") {
   std::string rest;
   ss >> rest;
   parsed(rest, conf.jitter);
  } else if (key == "maxjit") {
   std::string rest;
   ss >> rest;
   parsei(rest, conf.maxjit);
  } else if (key == "digits") {
   std::string rest;
   ss >> rest;
   parsei(rest, conf.digits);
  } else if (key == "writez") {
   std::string rest;
   ss >> rest;
   conf.writez = (rest == "1" || rest == "true" || rest == "yes");
  } else if (key == "writediag") {
   std::string rest;
   ss >> rest;
   conf.writediag = (rest == "1" || rest == "true" || rest == "yes");
  } else if (key == "item") {
   // item <name> <unit> <mu> <sd> <d> <lo_z> <hi_z> <decimals>
   Item it;
   std::string a[7];
   if (!(ss >> it.name >> it.unit >> a[0] >> a[1] >> a[2] >> a[3] >> a[4] >> a[5])) {
    err = "bad item line at " + std::to_string(lineno);
    return false;
   }
   if (!parsed(a[0], it.mu) || !parsed(a[1], it.sd) || !parsed(a[2], it.shift) ||
       !parsed(a[3], it.lo) || !parsed(a[4], it.hi) || !parsed(a[5], it.decim)) {
    err = "bad item numeric at " + std::to_string(lineno);
    return false;
   }
   if (!(it.sd > 0.0)) {
    err = "item sd must be positive: " + it.name;
    return false;
   }
   if (!(it.lo < it.hi)) {
    err = "item lower must be below upper: " + it.name;
    return false;
   }
   items.push_back(it);
  } else if (key == "corr") {
   std::vector<double> r;
   std::string tok;
   while (ss >> tok) {
    double v = 0.0;
    if (!parsed(tok, v)) {
     err = "bad corr value at " + std::to_string(lineno);
     return false;
    }
    r.push_back(v);
   }
   rows.push_back(r);
  } else {
   err = "unknown key: " + key;
   return false;
  }
 }
 conf.dim = (int)items.size();
 if (conf.dim <= 0) {
  err = "no item given";
  return false;
 }
 if (conf.npat <= 0) {
  err = "npat must be positive";
  return false;
 }
 if (conf.mode != "cross" && conf.mode != "series") {
  err = "mode must be cross or series";
  return false;
 }
 if (conf.mode == "series" && conf.points <= 0) {
  err = "points must be positive";
  return false;
 }
 if (conf.burnin < 0) {
  conf.burnin = 0;
 }
 if (conf.thin <= 0) {
  conf.thin = 1;
 }
 if (conf.relax < 0) {
  conf.relax = 0;
 }
 int n = conf.dim;
 corr.init(n);
 if (rows.size() == 0) {
  // 相関指定がなければ単位行列 (独立) とする
  for (int i = 0; i < n; i++) {
   corr.at(i, i) = 1.0;
  }
 } else {
  if ((int)rows.size() != n) {
   err = "corr must have exactly dim rows";
   return false;
  }
  for (int i = 0; i < n; i++) {
   if ((int)rows[i].size() != n) {
    err = "corr must be square";
    return false;
   }
   for (int j = 0; j < n; j++) {
    corr.at(i, j) = rows[i][j];
   }
  }
  for (int i = 0; i < n; i++) {
   if (!(corr.get(i, i) > 0.0)) {
    err = "corr diagonal must be positive";
    return false;
   }
  }
 }
 return true;
}

// -----------------------------------------------------------------------------
// サンプリング本体
// -----------------------------------------------------------------------------

// 相関行列と各項目のsdから分散共分散行列を作る関数
static void makecov(const std::vector<Item> &items, const Mat &corr, Mat &cov)
{
 int n = (int)items.size();
 cov.init(n);
 for (int i = 0; i < n; i++) {
  for (int j = 0; j < n; j++) {
   cov.at(i, j) = corr.get(i, j) * items[i].sd * items[j].sd;
  }
 }
 symmetrize(cov);
}

// 切断区間内に入る初期値を作る関数 (時系列モードの保険として残す)
[[maybe_unused]]
static void makeinit(const std::vector<Item> &items, std::vector<double> &z)
{
 int n = (int)items.size();
 z.assign(n, 0.0);
 for (int i = 0; i < n; i++) {
  double a = items[i].lo;
  double b = items[i].hi;
  double m = items[i].mu;
  double s = items[i].sd;
  if (a <= m && m <= b) {
   z[i] = m;
  } else if (std::isfinite(a) && std::isfinite(b)) {
   z[i] = 0.5 * (a + b);
  } else if (std::isfinite(a)) {
   z[i] = a + s;
  } else if (std::isfinite(b)) {
   z[i] = b - s;
  } else {
   z[i] = m;
  }
 }
}

// 対数スケールの値を検査値に戻す関数 (y = exp(z) + d)
static double toy(double z, double shift)
{
 double lim = std::log(std::numeric_limits<double>::max()) - 1.0;
 if (z > lim) {
  z = lim;
 }
 return std::exp(z) + shift;
}

// 患者IDを作る関数 (数字のみだと表計算ソフトが数値として扱い、
// 実在の患者番号と誤認されやすいので接頭辞を付ける)
static std::string patid(int k)
{
 char buf[32];
 std::snprintf(buf, sizeof(buf), "SL%06d", k);
 return std::string(buf);
}

// 小数桁数で丸める関数
static double roundto(double v, int dec)
{
 if (dec < 0) {
  return v;
 }
 if (dec > 8) {
  dec = 8;
 }
 double p = std::pow(10.0, (double)dec);
 return std::round(v * p) / p;
}

// 精度行列を使ったGibbsサンプラで切断多変量正規から1掃引する関数
static bool gibbssweep(const Mat &Q, const std::vector<Item> &items, std::vector<double> &z,
                       std::vector<double> &r, std::vector<int> &ord, bool randomscan,
                       std::mt19937_64 &rng, std::string &err)
{
 int n = (int)items.size();
 if (randomscan) {
  std::shuffle(ord.begin(), ord.end(), rng);
 }
 for (int oi = 0; oi < n; oi++) {
  int i = ord[oi];
  double qii = Q.get(i, i);
  if (!(qii > 0.0)) {
   err = "non positive precision diagonal";
   return false;
  }
  double sd = 1.0 / std::sqrt(qii);
  double m = z[i] - r[i] / qii;     // 条件付き平均
  double nv = 0.0;
  if (!sampletrunc(m, sd, items[i].lo, items[i].hi, rng, nv)) {
   err = "truncated normal sampling failed";
   return false;
  }
  double delta = nv - z[i];
  z[i] = nv;
  for (int k = 0; k < n; k++) {
   r[k] += delta * Q.get(k, i);    // r = Q (z - mu) を差分更新
  }
 }
 return true;
}

// CSVヘッダを書く関数
static void writehead(std::ostream &os, const Conf &conf, const std::vector<Item> &items)
{
 os << "patient_id";
 if (conf.mode == "series") {
  os << ",visit,week";
 }
 for (std::size_t i = 0; i < items.size(); i++) {
  os << "," << items[i].name;
 }
 if (conf.writez) {
  for (std::size_t i = 0; i < items.size(); i++) {
   os << ",z_" << items[i].name;
  }
 }
 os << "\n";
}

// 使い方を表示する関数
static void printusage()
{
 std::cerr <<
"synthgen - SynthLab pseudo laboratory value generator\n"
"\n"
"usage: synthgen < spec.txt > out.csv\n"
"\n"
"spec keys (one per line):\n"
"  seed <int>              乱数種\n"
"  npat <int>              患者数\n"
"  mode cross|series       断面 (cross) か時系列 (series)\n"
"  burnin <int>            Gibbs のバーンイン掃引数 (cross)\n"
"  thin <int>              互換のため受理 (cross では未使用)\n"
"  relax <int>             患者ごとの緩和掃引数 (cross)\n"
"  randomscan 0|1          Gibbs の走査順ランダム化\n"
"  points <int>            時系列の点数 (series)\n"
"  sfrac <float>           個人内変動の幅 (sigma に対する比, series)\n"
"  noise <float>           連結時ノイズ (sigma に対する比, series)\n"
"  interval <float>        検査間隔 (週, series)\n"
"  jitter <float>          Cholesky 用ジッタ初期値\n"
"  maxjit <int>            ジッタ試行回数\n"
"  digits <int>            出力有効桁 (丸めは item の decimals が優先)\n"
"  writez 0|1              対数スケール値も出力\n"
"  writediag 0|1           診断情報を stderr へ\n"
"  item <name> <unit> <mu> <sd> <d> <lo_z> <hi_z> <decimals>\n"
"  corr <v1> <v2> ... <vdim>   (dim 行ぶん繰り返す)\n";
}

// -----------------------------------------------------------------------------
// main
// -----------------------------------------------------------------------------

// エントリポイントを定義する関数
int main(int ac, char *av[])
{
 if (ac >= 2 && (std::strcmp(av[1], "-h") == 0 || std::strcmp(av[1], "--help") == 0)) {
  printusage();
  return 0;
 }
 std::ios::sync_with_stdio(false);

 Conf conf = defconf();
 std::vector<Item> items;
 Mat corr;
 std::string err;
 if (!readspec(std::cin, conf, items, corr, err)) {
  std::cerr << "spec error: " << err << "\n";
  return 1;
 }
 int n = conf.dim;

 // 対数スケールの分散共分散行列を構成する
 Mat cov;
 makecov(items, corr, cov);

 // Cholesky 分解 (P3: 相関を反映した高速サンプリングに使う)
 Mat L;
 double usedjit = 0.0;
 if (!robustchol(cov, L, conf.jitter, conf.maxjit, usedjit, err)) {
  std::cerr << "covariance error: " << err << "\n";
  return 1;
 }
 // 精度行列 Q = Sigma^{-1} (C: Gibbs の条件付き分布に使う)
 Mat Q;
 cholinv(L, Q);

 if (conf.writediag) {
  double inverr = 0.0;
  for (int i = 0; i < n; i++) {
   for (int j = 0; j < n; j++) {
    double s = 0.0;
    for (int k = 0; k < n; k++) {
     s += cov.get(i, k) * Q.get(k, j);
    }
    double t = (i == j) ? 1.0 : 0.0;
    inverr = std::max(inverr, std::fabs(s - t));
   }
  }
  std::cerr << "dim " << n << "\n";
  std::cerr << "usedjitter " << usedjit << "\n";
  std::cerr << "inverr " << inverr << "\n";
  std::cerr << "mode " << conf.mode << "\n";
 }

 std::ostringstream os;
 os.setf(std::ios::fixed);
 writehead(os, conf, items);

 if (conf.mode == "cross") {
  // ---------------------------------------------------------------------
  // 断面データ
  //
  // 患者ごとに「独立な」標本を作る。単一の Gibbs 連鎖の連続掃引を
  // 別患者として使うと隣接標本が強く自己相関し、相関の強い高次元では
  // 全患者がほぼ同じ値になる (実際にそのバグを出した)。
  //
  // そこで各患者について
  //   1. 無相関標準正規を逆関数法で引き、Cholesky 因子 L を掛けて
  //      z = mu + L w とする。切断がなければこれは厳密な標本である
  //   2. 切断区間へ射影する
  //   3. 精度行列 Q を使った Gibbs を relax 回だけ回し、切断つき分布へ緩和する
  // とする。1 が患者間の独立性を、3 が切断の厳密性を担う。
  // ---------------------------------------------------------------------
  std::vector<double> mu(n);
  for (int i = 0; i < n; i++) {
   mu[i] = items[i].mu;
  }
  std::vector<int> ord(n);
  for (int i = 0; i < n; i++) {
   ord[i] = i;
  }
  // 患者間ラグ1自己相関の診断用 (独立なら 0 付近になるはず)
  std::vector<double> s1(n, 0.0), s2(n, 0.0), sx(n, 0.0), prev(n, 0.0);
  long long clamped = 0;
  long long cells = 0;

  for (int p = 0; p < conf.npat; p++) {
   unsigned long long js = conf.seed + 1000003ULL * (unsigned long long)(p + 1);
   std::mt19937_64 rng(js);

   // 1. 無相関正規 -> Cholesky で相関を入れる
   std::vector<double> w(n, 0.0);
   for (int i = 0; i < n; i++) {
    w[i] = norminv(randunit(rng));
   }
   std::vector<double> z(n, 0.0);
   for (int i = 0; i < n; i++) {
    double acc = 0.0;
    for (int k = 0; k <= i; k++) {
     acc += L.get(i, k) * w[k];
    }
    z[i] = mu[i] + acc;
   }
   // 2. 切断区間へ射影
   for (int i = 0; i < n; i++) {
    cells++;
    if (z[i] < items[i].lo) {
     z[i] = items[i].lo;
     clamped++;
    } else if (z[i] > items[i].hi) {
     z[i] = items[i].hi;
     clamped++;
    }
   }
   // 3. 残差を初期化して Gibbs で緩和
   std::vector<double> r(n, 0.0);
   for (int i = 0; i < n; i++) {
    double acc = 0.0;
    for (int k = 0; k < n; k++) {
     acc += Q.get(i, k) * (z[k] - mu[k]);
    }
    r[i] = acc;
   }
   for (int t = 0; t < conf.relax; t++) {
    if (!gibbssweep(Q, items, z, r, ord, conf.randomscan, rng, err)) {
     std::cerr << "sampling error: " << err << "\n";
     return 1;
    }
   }

   os << patid(p + 1);
   for (int i = 0; i < n; i++) {
    double y = roundto(toy(z[i], items[i].shift), (int)items[i].decim);
    os.precision((int)std::max(0.0, items[i].decim));
    os << "," << y;
   }
   if (conf.writez) {
    os.precision(conf.digits);
    for (int i = 0; i < n; i++) {
     os << "," << z[i];
    }
   }
   os << "\n";

   for (int i = 0; i < n; i++) {
    s1[i] += z[i];
    s2[i] += z[i] * z[i];
    if (p > 0) {
     sx[i] += prev[i] * z[i];
    }
    prev[i] = z[i];
   }
  }

  if (conf.writediag) {
   double worst = 0.0;
   for (int i = 0; i < n; i++) {
    double m = s1[i] / (double)conf.npat;
    double den = s2[i] - (double)conf.npat * m * m;
    double num = (conf.npat > 1) ? (sx[i] - (double)(conf.npat - 1) * m * m) : 0.0;
    double ac = (den != 0.0) ? num / den : 0.0;
    if (std::fabs(ac) > std::fabs(worst)) {
     worst = ac;
    }
   }
   std::cerr << "relax " << conf.relax << "\n";
   std::cerr << "worstpatientlag1 " << worst << "\n";
   std::cerr << "clampedfrac " << (cells ? (double)clamped / (double)cells : 0.0) << "\n";
  }
 } else {
  // ---------------------------------------------------------------------
  // 時系列データ (P3): 個人の初期ベクトルを Cholesky で作り、
  // その周囲を狭い sigma で逆関数法サンプリングして時系列にする
  // ---------------------------------------------------------------------
  // 個人内変動の共分散 Sigma_local = diag(f sd) R diag(f sd) の Cholesky
  double f = conf.sfrac;
  if (!(f > 0.0)) {
   f = 1.0e-6;
  }
  Mat covl;
  covl.init(n);
  for (int i = 0; i < n; i++) {
   for (int j = 0; j < n; j++) {
    covl.at(i, j) = cov.get(i, j) * f * f;
   }
  }
  Mat Ll;
  double uj2 = 0.0;
  if (!robustchol(covl, Ll, conf.jitter, conf.maxjit, uj2, err)) {
   std::cerr << "local covariance error: " << err << "\n";
   return 1;
  }

  for (int p = 0; p < conf.npat; p++) {
   unsigned long long js = conf.seed + 1000003ULL * (unsigned long long)(p + 1);
   std::mt19937_64 rng(js);

   // 初期ベクトル z0: 無相関標準正規を逆関数法で引き、L を掛けて相関を入れる。
   // 集団分布の切断区間 [lo, hi] に入るまで最大 64 回引き直し、
   // 収まらない場合は成分ごとに区間へ射影する。
   std::vector<double> z0(n, 0.0);
   bool okinit = false;
   for (int trial = 0; trial < 64 && !okinit; trial++) {
    std::vector<double> w(n, 0.0);
    for (int i = 0; i < n; i++) {
     w[i] = norminv(randunit(rng));
    }
    for (int i = 0; i < n; i++) {
     double s = 0.0;
     for (int k = 0; k <= i; k++) {
      s += L.get(i, k) * w[k];
     }
     z0[i] = items[i].mu + s;
    }
    okinit = true;
    for (int i = 0; i < n; i++) {
     if (!(z0[i] >= items[i].lo && z0[i] <= items[i].hi)) {
      okinit = false;
     }
    }
   }
   for (int i = 0; i < n; i++) {
    if (z0[i] < items[i].lo) {
     z0[i] = items[i].lo;
    }
    if (z0[i] > items[i].hi) {
     z0[i] = items[i].hi;
    }
   }

   for (int t = 0; t < conf.points; t++) {
    std::vector<double> w(n, 0.0);
    for (int i = 0; i < n; i++) {
     w[i] = norminv(randunit(rng));
    }
    std::vector<double> z(n, 0.0);
    for (int i = 0; i < n; i++) {
     double s = 0.0;
     for (int k = 0; k <= i; k++) {
      s += Ll.get(i, k) * w[k];
     }
     // 連結時に加える独立ガウスノイズ (P3)
     double nz = 0.0;
     if (conf.noise > 0.0) {
      nz = conf.noise * items[i].sd * norminv(randunit(rng));
     }
     z[i] = z0[i] + s + nz;
     if (z[i] < items[i].lo) {
      z[i] = items[i].lo;
     }
     if (z[i] > items[i].hi) {
      z[i] = items[i].hi;
     }
    }
    os << patid(p + 1) << "," << (t + 1);
    os.precision(2);
    os << "," << (double)t * conf.interval;
    for (int i = 0; i < n; i++) {
     double y = roundto(toy(z[i], items[i].shift), (int)items[i].decim);
     os.precision((int)std::max(0.0, items[i].decim));
     os << "," << y;
    }
    if (conf.writez) {
     os.precision(conf.digits);
     for (int i = 0; i < n; i++) {
      os << "," << z[i];
     }
    }
    os << "\n";
   }
  }
 }

 std::cout << os.str();
 std::cout.flush();
 return 0;
}
