<?php
// ext/curl/src/curl_file.php — CURLFile + CURLStringFile 类（文件上传支持）
//
// 与 PHP 8.5 ext/curl/curl_file.stub.php 1:1 对齐；用于 multipart/form-data 文件上传。
//
// 设计说明（phpc 模式，无 C 代码）：
//   - 纯 PHP 实现，不依赖任何 C 扩展函数
//   - 不使用 ?type 语法（TinyPHP 不支持 nullable，用空字符串 "" 替代 null 默认值）
//   - 属性默认值与 PHP 8.5 一致（CURLFile 三属性默认 ""，CURLStringFile 三属性无默认值）
//   - CURLFile: 当 mime_type="" 时由调用方（curl_exec 构造 multipart 时）按文件扩展名推导
//   - CURLFile: 当 posted_filename="" 时使用 basename($filename) 作为上传文件名
//   - CURLStringFile: 必须显式提供 data 与 postname，mime 默认 "application/octet-stream"
//
// 与 PHP 原生行为差异：
//   - PHP 用 null 表示"未指定"，TinyPHP 用空字符串 "" 表示"未指定"
//   - 用户代码无需修改：empty($file->mime) 在两种语义下都成立

// ════════════════════════════════════════════════════════════
// CURLFile — 磁盘文件上传
// ════════════════════════════════════════════════════════════

class CURLFile
{
    // 上传文件的磁盘路径（必须）
    public string $name = "";
    // MIME 类型（空字符串表示由 curl_exec 按扩展名推导）
    public string $mime = "";
    // 上传时显示的文件名（空字符串表示使用 basename($name)）
    public string $postname = "";

    // ── 构造函数 ──────────────────────────────────────────────
    //   $filename       : 磁盘文件路径
    //   $mime_type      : MIME 类型（空字符串=自动推导，PHP 原生 null 等价）
    //   $posted_filename: 上传文件名（空字符串=用 basename，PHP 原生 null 等价）
    public function __construct(string $filename, string $mime_type = "", string $posted_filename = "")
    {
        $this->name = $filename;
        $this->mime = $mime_type;
        $this->postname = $posted_filename;
    }

    // ── 获取磁盘路径 ──────────────────────────────────────────
    public function getFilename(): string
    {
        return $this->name;
    }

    // ── 获取 MIME 类型 ────────────────────────────────────────
    public function getMimeType(): string
    {
        return $this->mime;
    }

    // ── 获取上传文件名 ────────────────────────────────────────
    public function getPostFilename(): string
    {
        return $this->postname;
    }

    // ── 设置 MIME 类型 ────────────────────────────────────────
    public function setMimeType(string $mime_type): void
    {
        $this->mime = $mime_type;
    }

    // ── 设置上传文件名 ────────────────────────────────────────
    public function setPostFilename(string $posted_filename): void
    {
        $this->postname = $posted_filename;
    }
}

// ════════════════════════════════════════════════════════════
// CURLStringFile — 字符串内容作为文件上传（since PHP 8.0）
// ════════════════════════════════════════════════════════════

class CURLStringFile
{
    // 文件内容（字符串）
    public string $data;
    // 上传时显示的文件名（必须）
    public string $postname;
    // MIME 类型（默认 application/octet-stream）
    public string $mime;

    // ── 构造函数 ──────────────────────────────────────────────
    //   $data    : 文件内容字符串
    //   $postname: 上传文件名
    //   $mime    : MIME 类型（默认 "application/octet-stream"）
    public function __construct(string $data, string $postname, string $mime = "application/octet-stream")
    {
        $this->data = $data;
        $this->postname = $postname;
        $this->mime = $mime;
    }
}
