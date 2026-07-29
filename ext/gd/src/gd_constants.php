<?php
// ext/gd/src/gd_constants.php — GD 扩展常量定义（89 个）
//
// 与 PHP 8.5 ext/gd/gd.stub.php 1:1 对齐；数值取自 libgd/gd.h 与
// ext/gd/gd_arginfo.h 中的实际定义。
//
// 说明：
//   - 图像类型常量（IMG_AVIF/IMG_GIF/...）为位掩码，可按位 OR 用于 getimagesize 返回的 type 字段
//   - IMG_JPG 与 IMG_JPEG 同值（4），为历史保留别名
//   - IMG_ARC_ROUNDED 与 IMG_ARC_PIE 同值（0），为历史保留别名
//   - 特殊颜色值为负数（-2 ~ -6），与正常调色板索引（>= 0）区分
//   - 插值方法常量值与 libgd gdInterpolationMethod 枚举一致
//   - GD_VERSION/GD_MAJOR_VERSION/... 对应 libgd 2.3.3（PHP 8.5 内置版本）

// ════════════════════════════════════════════════════════════
// IMG_* 图像类型位掩码（getimagesize type 字段、image_type_to_extension 等）
// ════════════════════════════════════════════════════════════
const IMG_AVIF = 1;                         // AVIF 图像
const IMG_GIF = 2;                          // GIF 图像
const IMG_JPG = 4;                          // JPEG 图像
const IMG_JPEG = 4;                         // alias of IMG_JPG
const IMG_PNG = 8;                          // PNG 图像
const IMG_WBMP = 16;                        // WBMP 图像
const IMG_XPM = 32;                         // XPM 图像
const IMG_WEBP = 64;                        // WebP 图像
const IMG_BMP = 128;                        // BMP 图像
const IMG_TGA = 256;                        // TGA 图像

// ════════════════════════════════════════════════════════════
// IMG_COLOR_* 特殊颜色值（用于 imagecolorallocate 返回值与画图函数 color 参数）
//   负数值，与正常调色板索引（>= 0）区分
// ════════════════════════════════════════════════════════════
const IMG_COLOR_TILED = -5;                 // 使用 tile 图像填充
const IMG_COLOR_STYLED = -2;                // 使用 style 画线
const IMG_COLOR_BRUSHED = -3;               // 使用 brush 图像画线
const IMG_COLOR_STYLEDBRUSHED = -4;         // 使用 brush + style 画线
const IMG_COLOR_TRANSPARENT = -6;           // 透明色

// ════════════════════════════════════════════════════════════
// IMG_ARC_* 弧形绘制标志（imagefilledarc 的 style 参数）
// ════════════════════════════════════════════════════════════
const IMG_ARC_ROUNDED = 0;                  // alias of IMG_ARC_PIE
const IMG_ARC_PIE = 0;                      // 扇形（含圆弧+两条半径+填充）
const IMG_ARC_CHORD = 1;                    // 弓形（含弦+两条半径+填充）
const IMG_ARC_NOFILL = 2;                   // 仅描边不填充
const IMG_ARC_EDGED = 4;                    // 描边时包含半径线

// ════════════════════════════════════════════════════════════
// IMG_GD2_* GD2 格式标志（imagegd2 的 chunk/format 参数）
// ════════════════════════════════════════════════════════════
const IMG_GD2_RAW = 1;                      // GD2 原始格式（无压缩）
const IMG_GD2_COMPRESSED = 2;               // GD2 压缩格式

// ════════════════════════════════════════════════════════════
// IMG_FLIP_* 翻转标志（imageflip 的 mode 参数）
// ════════════════════════════════════════════════════════════
const IMG_FLIP_HORIZONTAL = 1;              // 水平翻转
const IMG_FLIP_VERTICAL = 2;                // 垂直翻转
const IMG_FLIP_BOTH = 3;                    // 水平+垂直翻转

// ════════════════════════════════════════════════════════════
// IMG_EFFECT_* 图层效果标志（imagelayereffect 的 effect 参数）
// ════════════════════════════════════════════════════════════
const IMG_EFFECT_REPLACE = 0;               // 替换（不混合）
const IMG_EFFECT_ALPHABLEND = 1;            // Alpha 混合（正常绘制）
const IMG_EFFECT_NORMAL = 2;                // 正常混合
const IMG_EFFECT_OVERLAY = 3;               // 叠加模式
const IMG_EFFECT_MULTIPLY = 4;              // 乘法模式

// ════════════════════════════════════════════════════════════
// IMG_CROP_* 裁剪模式（imagecrop 的 mode 参数）
// ════════════════════════════════════════════════════════════
const IMG_CROP_DEFAULT = 0;                 // 默认裁剪（使用给定矩形）
const IMG_CROP_TRANSPARENT = 1;             // 裁剪掉透明区域
const IMG_CROP_BLACK = 2;                   // 裁剪掉黑色区域
const IMG_CROP_WHITE = 3;                   // 裁剪掉白色区域
const IMG_CROP_SIDES = 4;                   // 裁剪掉四边相同颜色
const IMG_CROP_THRESHOLD = 5;               // 阈值裁剪（需配合 color + threshold）

// ════════════════════════════════════════════════════════════
// IMG_* 插值方法（imagesetinterpolation 的 method 参数，对应 libgd gdInterpolationMethod）
// ════════════════════════════════════════════════════════════
const IMG_BELL = 1;                         // Bell 插值
const IMG_BESSEL = 2;                       // Bessel 插值
const IMG_BILINEAR_FIXED = 3;               // 双线性插值（定点，默认）
const IMG_BICUBIC = 4;                      // 双三次插值
const IMG_BICUBIC_FIXED = 5;                // 双三次插值（定点）
const IMG_BLACKMAN = 6;                     // Blackman 窗插值
const IMG_BOX = 7;                          // Box 插值
const IMG_BSPLINE = 8;                      // B-spline 插值
const IMG_CATMULLROM = 9;                   // Catmull-Rom 插值
const IMG_GAUSSIAN = 10;                    // 高斯插值
const IMG_GENERALIZED_CUBIC = 11;           // 广义三次插值
const IMG_HERMITE = 12;                     // Hermite 插值
const IMG_HAMMING = 13;                     // Hamming 窗插值
const IMG_HANNING = 14;                     // Hanning 窗插值
const IMG_MITCHELL = 15;                    // Mitchell 插值
const IMG_POWER = 16;                       // Power 插值
const IMG_QUADRATIC = 17;                   // 二次插值
const IMG_SINC = 18;                        // Sinc 插值
const IMG_NEAREST_NEIGHBOUR = 19;           // 最近邻插值
const IMG_WEIGHTED4 = 20;                   // 4 点加权插值
const IMG_TRIANGLE = 21;                    // 三角插值

// ════════════════════════════════════════════════════════════
// IMG_AFFINE_* 仿射变换类型（imageaffine 的 type 参数）
// ════════════════════════════════════════════════════════════
const IMG_AFFINE_TRANSLATE = 0;             // 平移
const IMG_AFFINE_SCALE = 1;                 // 缩放
const IMG_AFFINE_ROTATE = 2;                // 旋转
const IMG_AFFINE_SHEAR_HORIZONTAL = 3;      // 水平剪切
const IMG_AFFINE_SHEAR_VERTICAL = 4;        // 垂直剪切

// ════════════════════════════════════════════════════════════
// GD_* 版本信息（对应 libgd 2.3.3，PHP 8.5 内置版本）
// ════════════════════════════════════════════════════════════
const GD_BUNDLED = 1;                       // 是否使用 bundled libgd（始终为 1）
const GD_VERSION = "2.3.3";                 // libgd 完整版本字符串
const GD_MAJOR_VERSION = 2;                 // 主版本号
const GD_MINOR_VERSION = 3;                 // 次版本号
const GD_RELEASE_VERSION = 3;               // 发布版本号
const GD_EXTRA_VERSION = "";                // 额外版本字符串（无）

// ════════════════════════════════════════════════════════════
// IMG_FILTER_* 图像滤镜类型（imagefilter 的 filtertype 参数）
// ════════════════════════════════════════════════════════════
const IMG_FILTER_NEGATE = 0;                // 反色
const IMG_FILTER_GRAYSCALE = 1;             // 灰度
const IMG_FILTER_BRIGHTNESS = 2;            // 亮度（arg1=亮度值）
const IMG_FILTER_CONTRAST = 3;              // 对比度（arg1=对比度值）
const IMG_FILTER_COLORIZE = 4;              // 着色（arg1/arg2/arg3=RGB, arg4=alpha）
const IMG_FILTER_EDGEDETECT = 5;            // 边缘检测
const IMG_FILTER_GAUSSIAN_BLUR = 7;         // 高斯模糊
const IMG_FILTER_SELECTIVE_BLUR = 8;        // 选择性模糊
const IMG_FILTER_EMBOSS = 9;                // 浮雕
const IMG_FILTER_MEAN_REMOVAL = 10;         // 均值去除（素描效果）
const IMG_FILTER_SMOOTH = 11;               // 平滑（arg1=平滑度）
const IMG_FILTER_PIXELATE = 12;             // 像素化（arg1=块大小, arg2=高级模式）
const IMG_FILTER_SCATTER = 13;              // 散射（arg1/arg2=随机偏移范围）

// ════════════════════════════════════════════════════════════
// PNG_FILTER_* / PNG_NO_FILTER PNG 过滤器（imagepng 的 filters 参数）
//   为 zlib png_filter_flags 位掩码
// ════════════════════════════════════════════════════════════
const PNG_NO_FILTER = 0x00;                 // 无过滤器
const PNG_FILTER_NONE = 0x08;               // None 过滤器
const PNG_FILTER_SUB = 0x10;                // Sub 过滤器
const PNG_FILTER_UP = 0x20;                 // Up 过滤器
const PNG_FILTER_AVG = 0x40;                // Average 过滤器
const PNG_FILTER_PAETH = 0x80;              // Paeth 过滤器
const PNG_ALL_FILTERS = 0xF8;               // 全部过滤器

// ════════════════════════════════════════════════════════════
// IMG_WEBP_LOSSLESS WebP 无损压缩标志（imagewebp 的 quality 参数）
// ════════════════════════════════════════════════════════════
const IMG_WEBP_LOSSLESS = 101;              // WebP 无损模式（quality 参数专用值）
