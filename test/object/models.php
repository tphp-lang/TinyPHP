<?php // @skip — companion file, no class Main


// Product — 基础模型，测试属性类型 + 构造器提升
class Product
{
    public int $id;
    public string $name;
    public float $price;
    public int $stock;
    public bool $active;

    public function __construct(
        public string $sku,
        int $id,
        string $name,
        float $price,
        int $stock,
        bool $active
    ) {
        $this->id     = $id;
        $this->name   = $name;
        $this->price  = $price;
        $this->stock  = $stock;
        $this->active = $active;
    }

    public function getTotal(): float
    {
        return (float)($this->price * (float)$this->stock);
    }

    public function isInStock(): bool
    {
        return $this->stock > 0 && $this->active;
    }

    public function applyDiscount(float $pct): void
    {
        $this->price = $this->price * (1.0 - $pct / 100.0);
    }

    public function summary(): void
    {
        echo '  [#' . $this->id . ' ' . $this->name . ' $' . $this->price . ' x' . $this->stock . ']' . "\n";
    }

    public function __destruct()
    {
        echo '  ~Product#' . $this->id . "\n";
    }
}

// OrderItem — 订单项
class OrderItem
{
    public int $orderId;
    public int $productId;
    public int $quantity;

    public function __construct(int $orderId, int $productId, int $quantity)
    {
        $this->orderId   = $orderId;
        $this->productId = $productId;
        $this->quantity  = $quantity;
    }
}

// EnrichedItem — 聚合数据
class EnrichedItem
{
    public string $sku;
    public string $productName;
    public int $quantity;
    public float $unitPrice;
    public float $lineTotal;

    public function __construct(string $sku, string $name, int $qty, float $price)
    {
        $this->sku         = $sku;
        $this->productName = $name;
        $this->quantity    = $qty;
        $this->unitPrice   = $price;
        $this->lineTotal   = $price * (float)$qty;
    }
}
