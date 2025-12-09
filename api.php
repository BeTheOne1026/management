<?php
// api.php - Ultimate Monolith Version
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// --- 数据库配置 ---
$host = 'localhost:3306';
$db   = 'phonebetheone';
$user = 'phonebetheone';
$pass = '981026.zyf';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) { echo json_encode(['status'=>'error','message'=>'Database Connect Error']); exit; }

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents("php://input"), true) ?? $_POST;

function addLog($pdo, $uid, $type, $content) {
    $pdo->prepare("INSERT INTO system_logs (user_id, action_type, content) VALUES (?,?,?)")->execute([$uid, $type, $content]);
}

try {
    // 1. 登录
    if ($action == 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username=?");
        $stmt->execute([$input['username']]);
        $u = $stmt->fetch();
        if ($u && $u['password'] == $input['password']) {
            unset($u['password']);
            echo json_encode(['status'=>'success', 'data'=>$u]);
        } else { echo json_encode(['status'=>'error', 'message'=>'账号或密码错误']); }
    }

    // 2. 仪表盘
    elseif ($action == 'dashboard') {
        $today = date('Y-m-d');
        $data = [
            'today_sales_actual' => $pdo->query("SELECT SUM(actual_amount) FROM orders WHERE type='out' AND DATE(created_at)='$today'")->fetchColumn() ?: 0,
            'today_sales_receivable' => $pdo->query("SELECT SUM(total_amount) FROM orders WHERE type='out' AND DATE(created_at)='$today'")->fetchColumn() ?: 0,
            'total_stock' => $pdo->query("SELECT SUM(stock_quantity) FROM products")->fetchColumn() ?: 0,
            'low_stock_alert' => $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity < 5")->fetchColumn() ?: 0,
            'today_profit' => 0
        ];

        // 管理员看利润
        $uid = $_GET['user_id'] ?? 0;
        $role = $pdo->query("SELECT role FROM users WHERE id=$uid")->fetchColumn();
        if ($role === 'admin') {
            $cost = $pdo->query("SELECT SUM(oi.quantity * p.purchase_price) FROM order_items oi JOIN orders o ON oi.order_id=o.id JOIN products p ON oi.product_id=p.id WHERE o.type='out' AND DATE(o.created_at)='$today'")->fetchColumn() ?: 0;
            $data['today_profit'] = $data['today_sales_actual'] - $cost;
        }
        echo json_encode(['status'=>'success', 'data'=>$data]);
    }

    // 3. 销售趋势
    elseif ($action == 'sales_trend') {
        $res = [];
        for($i=6; $i>=0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $val = $pdo->query("SELECT SUM(actual_amount) FROM orders WHERE type='out' AND DATE(created_at)='$d'")->fetchColumn() ?: 0;
            $res[] = ['date'=>$d, 'total'=>$val];
        }
        echo json_encode(['status'=>'success', 'data'=>$res]);
    }

    // 4. 商品列表
    elseif ($action == 'products') {
        echo json_encode(['status'=>'success', 'data'=>$pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll()]);
    }

    // 5. 上传
    elseif ($action == 'upload') {
        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        $name = 'img_' . time() . rand(100,999) . '.' . pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['file']['tmp_name'], 'uploads/' . $name);
        echo json_encode(['status'=>'success', 'url'=>'uploads/' . $name]);
    }

    // 6. 新增商品
    // 6. 新增商品
    elseif ($action == 'add_product') {
        // [修复] 如果进价/售价为空，默认为 0，防止 SQL 报错
        $p_price = is_numeric($input['purchase_price']) ? $input['purchase_price'] : 0;
        $s_price = is_numeric($input['selling_price']) ? $input['selling_price'] : 0;

        $pdo->prepare("INSERT INTO products (type,accessory_type,brand,model,color,remark,purchase_price,selling_price,stock_quantity,images,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $input['type'],
                $input['accessory_type']??null,
                $input['brand'],
                $input['model'],
                $input['color'] ?? null,
                $input['remark'] ?? null,
                $p_price, // 使用处理后的变量
                $s_price, // 使用处理后的变量
                $input['stock_quantity'],
                $input['images'],
                $input['user_id']
            ]);

        $colorInfo = !empty($input['color']) ? "({$input['color']})" : "";
        addLog($pdo, $input['user_id'], 'CREATE', "入库: {$input['brand']} {$input['model']}{$colorInfo}");
        echo json_encode(['status'=>'success']);
    }

    // 7. 更新商品 (含Diff)
    // 7. 更新商品 (含Diff)
    // 7. 更新商品 (含Diff)
    elseif ($action == 'update_product') {
        $old = $pdo->query("SELECT * FROM products WHERE id={$input['id']}")->fetch();

        // [修复] 同样处理更新时的空值
        $p_price = is_numeric($input['purchase_price']) ? $input['purchase_price'] : 0;
        $s_price = is_numeric($input['selling_price']) ? $input['selling_price'] : 0;

        $pdo->prepare("UPDATE products SET type=?,accessory_type=?,brand=?,model=?,color=?,remark=?,purchase_price=?,selling_price=?,stock_quantity=?,images=? WHERE id=?")
            ->execute([
                $input['type'],
                $input['accessory_type']??null,
                $input['brand'],
                $input['model'],
                $input['color'] ?? null,
                $input['remark'] ?? null,
                $p_price, // 使用处理后的变量
                $s_price, // 使用处理后的变量
                $input['stock_quantity'],
                $input['images'],
                $input['id']
            ]);

        $diff = [];
        // [新增] 记录颜色和备注的变更日志
        if(($old['color']??'') != ($input['color']??'')) $diff[]="颜色:{$old['color']}->{$input['color']}";
        if(($old['remark']??'') != ($input['remark']??'')) $diff[]="备注修改"; // 备注太长通常不全记录

        if($old['stock_quantity']!=$input['stock_quantity']) $diff[]="库存:{$old['stock_quantity']}->{$input['stock_quantity']}";
        if($old['selling_price']!=$input['selling_price']) $diff[]="售价:{$old['selling_price']}->{$input['selling_price']}";

        addLog($pdo, $input['user_id'], 'UPDATE', empty($diff) ? "编辑商品信息" : "编辑[{$old['model']}]: ".implode(', ',$diff));
        echo json_encode(['status'=>'success']);
    }

    // 8. 结账
    elseif ($action == 'checkout') {
        $pdo->beginTransaction();
        try {
            $no = 'ORD'.date('YmdHis').rand(100,999);
            $pdo->prepare("INSERT INTO orders (order_no,type,total_amount,actual_amount,handler_id,buyer_name,buyer_phone,buyer_address,payment_method) VALUES (?,'out',?,?,?,?,?,?,?)")
                ->execute([$no, $input['total_amount'], $input['actual_amount'], $input['user_id'], $input['buyer_name'], $input['buyer_phone'], $input['buyer_address'], $input['payment_method']]);
            $oid = $pdo->lastInsertId();

            foreach($input['items'] as $item) {
                if($pdo->query("SELECT stock_quantity FROM products WHERE id={$item['id']}")->fetchColumn() < $item['qty']) throw new Exception("{$item['model']} 库存不足");
                $pdo->exec("UPDATE products SET stock_quantity=stock_quantity-{$item['qty']} WHERE id={$item['id']}");
                $pdo->prepare("INSERT INTO order_items (order_id,product_id,quantity,price) VALUES (?,?,?,?)")->execute([$oid, $item['id'], $item['qty'], $item['selling_price']]);
            }
            addLog($pdo, $input['user_id'], 'SALE', "销售单$no (实收:{$input['actual_amount']})");
            $pdo->commit();
            echo json_encode(['status'=>'success']);
        } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]); }
    }

    // 9. 订单列表
    elseif ($action == 'orders') {
        echo json_encode(['status'=>'success', 'data'=>$pdo->query("SELECT o.*, u.real_name as handler_name FROM orders o LEFT JOIN users u ON o.handler_id=u.id ORDER BY o.id DESC")->fetchAll()]);
    }

    // 10. 订单详情
    elseif ($action == 'order_details') {
        echo json_encode(['status'=>'success', 'data'=>$pdo->query("SELECT oi.*, p.brand, p.model, p.type, p.accessory_type FROM order_items oi LEFT JOIN products p ON oi.product_id=p.id WHERE oi.order_id={$_GET['order_id']}")->fetchAll()]);
    }

    // 11. 日志 (权限)
    elseif ($action == 'logs') {
        $uid = $_GET['user_id'];
        $role = $pdo->query("SELECT role FROM users WHERE id=$uid")->fetchColumn();
        $sql = "SELECT l.*, u.real_name FROM system_logs l LEFT JOIN users u ON l.user_id=u.id " . ($role!=='admin' ? "WHERE l.user_id=$uid" : "") . " ORDER BY l.id DESC";
        echo json_encode(['status'=>'success', 'data'=>$pdo->query($sql)->fetchAll()]);
    }

    // 12. 员工管理
    elseif ($action == 'users') { echo json_encode(['status'=>'success', 'data'=>$pdo->query("SELECT * FROM users")->fetchAll()]); }
    elseif ($action == 'add_user') {
        $pdo->prepare("INSERT INTO users (username,password,real_name,role) VALUES (?,?,?,?)")->execute([$input['username'],$input['password'],$input['real_name'],$input['role']]);
        addLog($pdo, $input['operator_id'], 'USER_ADD', "添加员工: {$input['real_name']}");
        echo json_encode(['status'=>'success']);
    }
    elseif ($action == 'delete_user') {
        $name = $pdo->query("SELECT real_name FROM users WHERE id={$input['id']}")->fetchColumn();
        $pdo->exec("DELETE FROM users WHERE id={$input['id']}");
        addLog($pdo, $input['operator_id'], 'USER_DEL', "删除员工: $name");
        echo json_encode(['status'=>'success']);
    }
    elseif ($action == 'change_password') {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$input['new_password'], $input['user_id']]);
        addLog($pdo, $input['user_id'], 'PWD_CHANGE', "修改密码");
        echo json_encode(['status'=>'success']);
    }
    // ... 前面的代码 ...

    // [修改] 13. 数据导出 (修复 fputcsv 警告)
    elseif ($action == 'export') {
        // 0. 防止警告破坏文件 (关键)
        error_reporting(0);
        ini_set('display_errors', 0);

        // 1. 权限验证
        $uid = $_GET['user_id'] ?? 0;
        $role = $pdo->query("SELECT role FROM users WHERE id=$uid")->fetchColumn();
        if ($role !== 'admin') { http_response_code(403); echo "Access Denied"; exit; }

        $type = $_GET['type'] ?? '';
        $filename = "export_{$type}_" . date('Ymd_His') . ".csv";

        // 2. 设置头信息
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");

        // 3. 输出 BOM 头
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');

        // 定义一个兼容的写行函数，自动补全参数
        // 参数说明: $handle, $fields, $separator, $enclosure, $escape
        function writeCsvRow($handle, $data) {
            fputcsv($handle, $data, ",", "\"", "\\");
        }

        // 4. 根据类型导出不同数据
        if ($type == 'inventory') {
            // 表头
            writeCsvRow($output, ['ID', '类型', '二级分类', '品牌', '型号', '颜色', '进价', '售价', '库存', '备注', '入库时间']);
            // 数据
            $rows = $pdo->query("SELECT id, type, accessory_type, brand, model, color, purchase_price, selling_price, stock_quantity, remark, created_at FROM products ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row) {
                $row['type'] = ($row['type']=='phone'?'手机':($row['type']=='accessory'?'配件':'家电'));
                writeCsvRow($output, $row);
            }
        }
        elseif ($type == 'orders') {
            writeCsvRow($output, ['订单号', '总金额', '实收', '经手人', '买家', '电话', '支付方式', '时间']);
            $sql = "SELECT o.order_no, o.total_amount, o.actual_amount, u.real_name, o.buyer_name, o.buyer_phone, o.payment_method, o.created_at FROM orders o LEFT JOIN users u ON o.handler_id=u.id ORDER BY o.id DESC";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row) writeCsvRow($output, $row);
        }
        elseif ($type == 'logs') {
            writeCsvRow($output, ['时间', '操作人', '类型', '内容']);
            $sql = "SELECT l.created_at, u.real_name, l.action_type, l.content FROM system_logs l LEFT JOIN users u ON l.user_id=u.id ORDER BY l.id DESC";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row) writeCsvRow($output, $row);
        }
        elseif ($type == 'staff') {
            writeCsvRow($output, ['ID', '账号', '姓名', '角色', '创建时间']);
            $rows = $pdo->query("SELECT id, username, real_name, role, created_at FROM users")->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row) writeCsvRow($output, $row);
        }

        fclose($output);
        exit;
    }

    // ... catch (Exception $e) ...

} catch (Exception $e) { echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]); }
?>