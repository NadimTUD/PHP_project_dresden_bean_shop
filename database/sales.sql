SELECT p.name,
       SUM(i.quantity) AS units,
       SUM(i.quantity::bigint * i.unit_price_cents) AS revenue_cents
FROM order_items AS i
JOIN products AS p ON p.id = i.product_id
JOIN orders AS o ON o.id = i.order_id
WHERE o.status <> 'cancelled'
GROUP BY p.id, p.name
ORDER BY revenue_cents DESC, p.name;
