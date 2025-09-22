<?php
require_once __DIR__ . '/config.php';

if (empty($_SESSION['shd_token'])) $_SESSION['shd_token'] = bin2hex(random_bytes(24));
function shd_csrf(){ return '<input type="hidden" name="shd_csrf" value="'.htmlspecialchars($_SESSION['shd_token']).'">'; }
function shd_check(){ if($_SERVER['REQUEST_METHOD']==='POST'){ $t=$_POST['shd_csrf']??''; if(!hash_equals($_SESSION['shd_token'],$t)){ http_response_code(400); exit('csrf'); } } }

$shd_view = $_GET['p'] ?? 'home';
$shd_errors = [];
$shd_msg = null;

$shd_public = ['login','register'];
if (!shd_uid() && !in_array($shd_view, $shd_public)) { header("Location: ?p=login"); exit; }

function shd_clean($v){ return trim((string)$v); }
function shd_go($to){ header("Location: ?p=$to"); exit; }
function shd_go_id($to,$id){ header("Location: ?p=$to&id=$id"); exit; }

$shd_upload_dir = __DIR__ . '/uploads';
$shd_upload_url = 'uploads/';

/* العمليات */
if ($shd_view === 'register' && $_SERVER['REQUEST_METHOD']==='POST') {
  shd_check();
  $nm = shd_clean($_POST['nm']??'');
  $em = shd_clean($_POST['em']??'');
  $pw = (string)($_POST['pw']??'');
  if($nm==='') $shd_errors[]='الاسم مطلوب';
  if(!filter_var($em, FILTER_VALIDATE_EMAIL)) $shd_errors[]='الايميل غير صالح';
  if(strlen($pw)<6) $shd_errors[]='كلمة المرور 6 أحرف على الأقل';
  if(!$shd_errors){
    $q = $shd_link->prepare("SELECT id FROM users WHERE email=? LIMIT 1"); $q->execute([$em]);
    if($q->fetch()) $shd_errors[]='الايميل مستخدم';
  }
  if(!$shd_errors){
    $h = password_hash($pw, PASSWORD_DEFAULT);
    $shd_link->prepare("INSERT INTO users(full_name,email,pass_hash) VALUES(?,?,?)")->execute([$nm,$em,$h]);
    $shd_msg = 'تم إنشاء الحساب، سجّل دخولك'; $shd_view = 'login';
  }
}

if ($shd_view === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
  shd_check();
  $em = shd_clean($_POST['em']??'');
  $pw = (string)($_POST['pw']??'');
  $q = $shd_link->prepare("SELECT id,full_name,pass_hash FROM users WHERE email=? LIMIT 1");
  $q->execute([$em]); $u = $q->fetch();
  if(!$u || !password_verify($pw,$u['pass_hash'])) $shd_errors[]='بيانات غير صحيحة';
  else {
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['shd_uid']   = (int)$u['id'];
    $_SESSION['shd_name']  = $u['full_name'];
    $_SESSION['shd_token'] = bin2hex(random_bytes(24));
    shd_go('dashboard');
  }
}

if ($shd_view === 'logout') {
  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $p = session_get_cookie_params();
      setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
  }
  shd_go('login');
}

if ($shd_view === 'cat_add' && $_SERVER['REQUEST_METHOD']==='POST') {
  shd_check();
  $cn = shd_clean($_POST['cn']??'');
  if($cn==='') $shd_errors[]='اسم الفئة مطلوب';
  if(!$shd_errors){
    $shd_link->prepare("INSERT INTO categories(cat_name) VALUES(?)")->execute([$cn]);
    $shd_msg='تمت إضافة الفئة'; $shd_view='cat_list';
  }
}

if ($shd_view === 'news_add' && $_SERVER['REQUEST_METHOD']==='POST') {
  shd_check();
  $ti = shd_clean($_POST['ti']??'');
  $ca = intval($_POST['ca']??0);
  $de = shd_clean($_POST['de']??'');
  $im = null;
  if(isset($_FILES['im']) && $_FILES['im']['error']===UPLOAD_ERR_OK){
    $ext = strtolower(pathinfo($_FILES['im']['name'], PATHINFO_EXTENSION));
    if(!in_array($ext,['jpg','jpeg','png','gif','webp'])) $shd_errors[]='نوع الصورة غير مسموح';
    else{
      if(!is_dir($shd_upload_dir)) mkdir($shd_upload_dir,0777,true);
      $fname = 'n_'.date('Ymd_His').'_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $shd_upload_dir . '/' . $fname;
      if(move_uploaded_file($_FILES['im']['tmp_name'],$dest)) $im = $shd_upload_url.$fname;
      else $shd_errors[]='فشل رفع الصورة';
    }
  }
  if($ti==='') $shd_errors[]='العنوان مطلوب';
  if($ca<=0) $shd_errors[]='اختر فئة';
  if($de==='') $shd_errors[]='تفاصيل الخبر مطلوبة';
  $author = shd_uid(); if(!$author){ shd_go('login'); }
  $chk = $shd_link->prepare("SELECT id FROM users WHERE id=? LIMIT 1"); $chk->execute([$author]);
  if(!$chk->fetch()){ $shd_errors[]='جلسة غير صالحة، سجّل دخولك من جديد'; }
  if(!$shd_errors){
    $shd_link->prepare("INSERT INTO news(title,category_id,details,image_path,author_id) VALUES(?,?,?,?,?)")
             ->execute([$ti,$ca,$de,$im,$author]);
    $shd_msg='تمت إضافة الخبر'; $shd_view='news_list';
  }
}

if ($shd_view === 'news_del' && $_SERVER['REQUEST_METHOD']==='POST') {
  shd_check();
  $id = intval($_POST['id']??0);
  $shd_link->prepare("UPDATE news SET deleted_at=NOW() WHERE id=?")->execute([$id]);
  $shd_msg='تم حذف الخبر'; shd_go('news_list');
}

if ($shd_view === 'news_edit' && $_SERVER['REQUEST_METHOD']==='POST') {
  shd_check();
  $id = intval($_POST['id']??0);
  $old = shd_one($shd_link,$id);
  $ti = shd_clean($_POST['ti']??'');
  $ca = intval($_POST['ca']??0);
  $de = shd_clean($_POST['de']??'');
  $im_keep = shd_clean($_POST['im_keep']??'');
  $im = $im_keep ?: null;
  if(isset($_FILES['im']) && $_FILES['im']['error']===UPLOAD_ERR_OK){
    $ext = strtolower(pathinfo($_FILES['im']['name'], PATHINFO_EXTENSION));
    if(in_array($ext,['jpg','jpeg','png','gif','webp'])){
      if(!is_dir($shd_upload_dir)) mkdir($shd_upload_dir,0777,true);
      $fname = 'n_'.date('Ymd_His').'_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $shd_upload_dir . '/' . $fname;
      if(move_uploaded_file($_FILES['im']['tmp_name'],$dest)) $im = $shd_upload_url.$fname;
    }
  }
  $shd_link->prepare("UPDATE news SET title=?,category_id=?,details=?,image_path=?,updated_at=NOW() WHERE id=?")
           ->execute([$ti,$ca,$de,$im,$id]);

  $cats = shd_cats($shd_link);
  $catName = function($id) use ($cats){ foreach($cats as $c) if($c['id']==$id) return $c['cat_name']; return '—'; };
  $_SESSION['shd_diff'] = [
    'title_old' => $old['title'] ?? '',
    'title_new' => $ti,
    'cat_old'   => $catName($old['category_id'] ?? 0),
    'cat_new'   => $catName($ca),
    'body_old'  => $old['details'] ?? '',
    'body_new'  => $de,
    'img_old'   => $old['image_path'] ?? '',
    'img_new'   => $im,
  ];
  header("Location: ?p=news_list");
  exit;
}

/* استعلامات مساعدة */
function shd_cats($pdo){ return $pdo->query("SELECT id, cat_name, created_at FROM categories ORDER BY id DESC")->fetchAll(); }
function shd_news($pdo,$all=false){
  $w=$all?'1=1':'deleted_at IS NULL';
  return $pdo->query("SELECT n.*,c.cat_name,u.full_name,u.id AS author_id
                      FROM news n
                      JOIN categories c ON n.category_id=c.id
                      JOIN users u ON n.author_id=u.id
                      WHERE $w
                      ORDER BY n.id DESC")->fetchAll();
}
function shd_one($pdo,$id){ $q=$pdo->prepare("SELECT * FROM news WHERE id=? LIMIT 1"); $q->execute([$id]); return $q->fetch(); }
function shd_full($pdo,$id){
  $q=$pdo->prepare("SELECT n.*,c.cat_name,u.full_name,u.id AS author_id
                    FROM news n
                    JOIN categories c ON n.category_id=c.id
                    JOIN users u ON n.author_id=u.id
                    WHERE n.id=? LIMIT 1");
  $q->execute([$id]); return $q->fetch();
}

/* تفعيل العنصر الحالي */
function shd_active($views){
  $cur = $GLOBALS['shd_view'] ?? '';
  foreach ((array)$views as $v) if ($cur === $v) return ' active';
  return '';
}

/* الهيدر (قالب Bootswatch Lux) */
function shd_header($title='shahd'){ ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Bootswatch Lux RTL (Bootstrap 5) -->
  <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/lux/bootstrap.rtl.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <title><?php echo htmlspecialchars($title); ?></title>
</head>
<body class="shd-body">
  <nav class="navbar navbar-expand-lg navbar-dark shd-nav shadow-sm sticky-top">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="?p=home">
        <i class="bi bi-newspaper fs-4"></i> SHAHD
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#shdNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="shdNav">
        <ul class="navbar-nav me-auto shd-menu">
          <li class="nav-item"><a class="nav-link<?php echo shd_active('home'); ?>" href="?p=home"><i class="bi bi-house-door"></i> الرئيسية</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?php echo shd_active(['news_add','news_list']); ?>" data-bs-toggle="dropdown" href="#"><i class="bi bi-megaphone"></i> الأخبار</a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="?p=news_add"><i class="bi bi-plus-circle"></i> إضافة خبر</a></li>
              <li><a class="dropdown-item" href="?p=news_list"><i class="bi bi-card-list"></i> عرض جميع الأخبار</a></li>
              <li><a class="dropdown-item<?php echo shd_active('news_deleted'); ?>" href="?p=news_deleted"><i class="bi bi-trash3"></i> المحذوفة</a></li>
            </ul>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?php echo shd_active(['cat_add','cat_list']); ?>" data-bs-toggle="dropdown" href="#"><i class="bi bi-tags"></i> الفئات</a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="?p=cat_add"><i class="bi bi-plus-circle"></i> إضافة فئة</a></li>
              <li><a class="dropdown-item" href="?p=cat_list"><i class="bi bi-grid"></i> عرض الفئات</a></li>
            </ul>
          </li>
        </ul>
        <ul class="navbar-nav">
          <?php if (shd_uid()): ?>
            <li class="nav-item"><a class="nav-link<?php echo shd_active('dashboard'); ?>" href="?p=dashboard"><i class="bi bi-speedometer2"></i> لوحة التحكم</a></li>
            <li class="nav-item ms-lg-2"><a class="btn btn-warning text-dark" href="?p=logout"><i class="bi bi-box-arrow-right"></i> خروج</a></li>
          <?php else: ?>
            <li class="nav-item"><a class="nav-link<?php echo shd_active('login'); ?>" href="?p=login">دخول</a></li>
            <li class="nav-item ms-lg-2"><a class="btn btn-primary" href="?p=register">حساب جديد</a></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <header class="shd-hero">
    <div class="container py-4">
      <div class="p-4 p-md-5 rounded shd-hero-card">
        <h1 class="mb-2 h3">مرحباً بك</h1>
        <p class="mb-0 text-muted">تجد بالأسفل آخر الأخبار المخزّنة في قاعدة البيانات.</p>
      </div>
    </div>
  </header>

  <main class="container my-4">
<?php }

/* الفوتر */
function shd_footer(){ ?>
    <footer class="text-center text-muted small my-5">
      <div class="d-inline-flex align-items-center gap-2">
        <i class="bi bi-newspaper"></i> SHAHD News Manager
      </div>
    </footer>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php }

/* الصفحات */
if ($shd_view==='home'){
  shd_header('الرئيسية');
  $all = shd_news($shd_link,false);
  if(!$all) echo '<div class="alert alert-secondary">لا توجد أخبار بعد.</div>';
  foreach($all as $row): ?>
    <article class="card shd-card mb-3">
      <div class="card-body d-flex gap-3 align-items-start">
        <img class="shd-thumb" src="<?php echo htmlspecialchars($row['image_path']?:'logo.svg');?>" alt="">
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge shd-badge"><?php echo htmlspecialchars($row['cat_name']); ?></span>
            <small class="text-muted">بواسطة <?php echo htmlspecialchars($row['full_name']); ?> • <?php echo htmlspecialchars($row['created_at']); ?></small>
          </div>
          <h5 class="mb-2"><a class="text-decoration-none link-dark" href="?p=news_view&id=<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['title']); ?></a></h5>
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-primary" href="?p=news_view&id=<?php echo $row['id']; ?>"><i class="bi bi-eye"></i> عرض</a>
            <?php if (shd_uid()): ?>
              <a class="btn btn-sm btn-outline-secondary" href="?p=news_edit&id=<?php echo $row['id']; ?>"><i class="bi bi-pencil"></i> تعديل</a>
              <form method="post" action="?p=news_del" class="d-inline">
                <?php echo shd_csrf(); ?>
                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')"><i class="bi bi-trash"></i> حذف</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </article>
  <?php endforeach; shd_footer(); exit;
}

elseif ($shd_view==='register'){
  shd_header('إنشاء حساب');
  if($shd_msg) echo '<div class="alert alert-success">'.$shd_msg.'</div>';
  if($shd_errors) { echo '<div class="alert alert-danger"><ul class="mb-0">'; foreach($shd_errors as $e) echo '<li>'.$e.'</li>'; echo '</ul></div>'; } ?>
  <div class="card shd-card p-3">
    <form method="post" class="row g-3">
      <?php echo shd_csrf(); ?>
      <div class="col-12"><label class="form-label">الاسم</label><input class="form-control" name="nm" required></div>
      <div class="col-12"><label class="form-label">الايميل</label><input class="form-control" name="em" type="email" required></div>
      <div class="col-12"><label class="form-label">كلمة المرور</label><input class="form-control" name="pw" type="password" minlength="6" required></div>
      <div class="col-12"><button class="btn btn-primary w-100">إنشاء</button></div>
    </form>
  </div>
  <?php shd_footer(); exit;
}

elseif ($shd_view==='login'){
  shd_header('تسجيل الدخول');
  if($shd_msg) echo '<div class="alert alert-success">'.$shd_msg.'</div>';
  if($shd_errors) { echo '<div class="alert alert-danger"><ul class="mb-0">'; foreach($shd_errors as $e) echo '<li>'.$e.'</li>'; echo '</ul></div>'; } ?>
  <div class="card shd-card p-3">
    <form method="post" class="row g-3">
      <?php echo shd_csrf(); ?>
      <div class="col-12"><label class="form-label">الايميل</label><input class="form-control" name="em" type="email" required></div>
      <div class="col-12"><label class="form-label">كلمة المرور</label><input class="form-control" name="pw" type="password" required></div>
      <div class="col-12"><button class="btn btn-primary w-100">دخول</button></div>
    </form>
  </div>
  <?php shd_footer(); exit;
}

elseif ($shd_view==='dashboard'){
  shd_header('لوحة التحكم'); ?>
  <div class="alert alert-info"><i class="bi bi-person-check"></i> أهلاً <?php echo htmlspecialchars($_SESSION['shd_name']??''); ?>.</div>
<?php shd_footer(); exit; }

elseif ($shd_view==='cat_add'){
  shd_header('إضافة فئة');
  if($shd_msg) echo '<div class="alert alert-success">'.$shd_msg.'</div>';
  if($shd_errors){ echo '<div class="alert alert-danger"><ul class="mb-0">'; foreach($shd_errors as $e) echo '<li>'.$e.'</li>'; echo '</ul></div>'; } ?>
  <div class="card shd-card p-3">
    <form method="post" class="row g-3">
      <?php echo shd_csrf(); ?>
      <div class="col-12"><label class="form-label">اسم الفئة</label><input class="form-control" name="cn" required></div>
      <div class="col-12"><button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> حفظ</button></div>
    </form>
  </div>
<?php shd_footer(); exit; }

elseif ($shd_view==='cat_list'){
  shd_header('عرض الفئات'); $rows = shd_cats($shd_link); ?>
  <div class="card shd-card p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-dark"><tr><th style="width:80px">#</th><th>اسم الفئة</th><th style="width:220px">أُنشئت</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr><td><?php echo $r['id']; ?></td><td><?php echo htmlspecialchars($r['cat_name']); ?></td><td><?php echo htmlspecialchars($r['created_at']); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php shd_footer(); exit; }

elseif ($shd_view==='news_add'){
  shd_header('إضافة خبر'); $cats = shd_cats($shd_link);
  if(!$cats){ echo '<div class="alert alert-warning">أضف فئة أولاً</div>'; shd_footer(); exit; }
  if($shd_msg) echo '<div class="alert alert-success">'.$shd_msg.'</div>';
  if($shd_errors){ echo '<div class="alert alert-danger"><ul class="mb-0">'; foreach($shd_errors as $e) echo '<li>'.$e.'</li>'; echo '</ul></div>'; } ?>
  <div class="card shd-card p-3">
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <?php echo shd_csrf(); ?>
      <div class="col-12"><label class="form-label">العنوان</label><input class="form-control" name="ti" required></div>
      <div class="col-12"><label class="form-label">الفئة</label>
        <select class="form-select" name="ca" required>
          <option value="">— اختر —</option>
          <?php foreach($cats as $c) echo '<option value="'.$c['id'].'">'.htmlspecialchars($c['cat_name']).'</option>'; ?>
        </select>
      </div>
      <div class="col-12"><label class="form-label">تفاصيل الخبر</label><textarea class="form-control" name="de" rows="5" required></textarea></div>
      <div class="col-12"><label class="form-label">صورة الخبر</label><input class="form-control" type="file" name="im" accept="image/*"></div>
      <div class="col-12"><button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> حفظ</button></div>
    </form>
  </div>
<?php shd_footer(); exit; }

elseif ($shd_view==='news_list'){
  shd_header('عرض جميع الأخبار'); $rows = shd_news($shd_link,false);

  if (!empty($_SESSION['shd_diff'])) {
    $d = $_SESSION['shd_diff']; unset($_SESSION['shd_diff']); ?>
    <div class="card shd-card p-3 mb-3">
      <h5 class="mb-3"><i class="bi bi-check2-square"></i> تم حفظ التعديلات</h5>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead class="table-secondary"><tr><th>الحقل</th><th>قبل</th><th>بعد</th></tr></thead>
          <tbody>
            <tr><td>العنوان</td><td><?php echo htmlspecialchars($d['title_old']); ?></td><td><?php echo htmlspecialchars($d['title_new']); ?></td></tr>
            <tr><td>الفئة</td><td><?php echo htmlspecialchars($d['cat_old']); ?></td><td><?php echo htmlspecialchars($d['cat_new']); ?></td></tr>
            <tr><td>التفاصيل</td><td style="white-space:pre-wrap"><?php echo htmlspecialchars($d['body_old']); ?></td><td style="white-space:pre-wrap"><?php echo htmlspecialchars($d['body_new']); ?></td></tr>
            <tr><td>الصورة</td><td><?php echo $d['img_old']?'<img class="shd-thumb" src="'.htmlspecialchars($d['img_old']).'">':'—'; ?></td><td><?php echo $d['img_new']?'<img class="shd-thumb" src="'.htmlspecialchars($d['img_new']).'">':'—'; ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  <?php } ?>

  <div class="card shd-card p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-dark">
        <tr>
          <th style="width:70px">#</th>
          <th>العنوان</th>
          <th>الفئة</th>
          <th style="width:120px">صورة</th>
          <th>الكاتب</th>
          <th style="width:110px">Id المستخدم</th>
          <th style="width:220px">تحكّم</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo $r['id']; ?></td>
          <td class="fw-semibold"><?php echo htmlspecialchars($r['title']); ?></td>
          <td><span class="badge shd-badge"><?php echo htmlspecialchars($r['cat_name']); ?></span></td>
          <td><?php if($r['image_path']) echo '<img class="shd-thumb" src="'.htmlspecialchars($r['image_path']).'">'; ?></td>
          <td><?php echo htmlspecialchars($r['full_name']); ?></td>
          <td><?php echo (int)$r['author_id']; ?></td>
          <td class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-primary" href="?p=news_view&id=<?php echo $r['id']; ?>"><i class="bi bi-eye"></i> عرض</a>
            <a class="btn btn-sm btn-outline-secondary" href="?p=news_edit&id=<?php echo $r['id']; ?>"><i class="bi bi-pencil"></i> تعديل</a>
            <form method="post" action="?p=news_del">
              <?php echo shd_csrf(); ?>
              <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
              <button class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')"><i class="bi bi-trash"></i> حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php shd_footer(); exit; }

elseif ($shd_view==='news_deleted'){
  shd_header('الأخبار المحذوفة');
  $rows = shd_news($shd_link,true); ?>
  <div class="card shd-card p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-dark">
        <tr>
          <th style="width:70px">#</th>
          <th>العنوان</th>
          <th style="width:160px">الفئة</th>
          <th>تفاصيل الخبر</th>
          <th style="width:120px">صورة</th>
          <th style="width:110px">Id المستخدم</th>
          <th style="width:170px">تاريخ الحذف</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): if(!$r['deleted_at']) continue;
        $details = htmlspecialchars($r['details']);
        if (mb_strlen($details) > 140) $details = mb_substr($details, 0, 140) . ' …'; ?>
        <tr>
          <td><?php echo $r['id']; ?></td>
          <td class="fw-semibold"><?php echo htmlspecialchars($r['title']); ?></td>
          <td><span class="badge shd-badge"><?php echo htmlspecialchars($r['cat_name']); ?></span></td>
          <td style="white-space:pre-wrap"><?php echo $details; ?></td>
          <td><?php echo $r['image_path']?'<img class="shd-thumb" src="'.htmlspecialchars($r['image_path']).'">':'—'; ?></td>
          <td><?php echo (int)$r['author_id']; ?></td>
          <td><?php echo htmlspecialchars($r['deleted_at']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php shd_footer(); exit; }

elseif ($shd_view==='news_view'){
  $id=intval($_GET['id']??0);
  $item=shd_full($shd_link,$id);
  if(!$item){ shd_header('غير موجود'); echo '<div class="card shd-card p-3">غير موجود</div>'; shd_footer(); exit; }
  shd_header($item['title']); ?>
  <article class="card shd-card p-3 d-grid gap-3">
    <div class="d-flex align-items-center justify-content-between">
      <span class="badge shd-badge"><?php echo htmlspecialchars($item['cat_name']); ?></span>
      <a class="btn btn-sm btn-outline-secondary" href="?p=news_list"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </div>
    <?php if($item['image_path']) echo '<img class="w-100 rounded-3" style="max-height:380px;object-fit:cover" src="'.htmlspecialchars($item['image_path']).'">'; ?>
    <h2 class="mb-1"><?php echo htmlspecialchars($item['title']); ?></h2>
    <div class="text-muted small">بواسطة <?php echo htmlspecialchars($item['full_name']); ?> • <?php echo htmlspecialchars($item['created_at']); ?></div>
    <div class="pt-2" style="line-height:1.9"><?php echo nl2br(htmlspecialchars($item['details'])); ?></div>
  </article>
  <?php shd_footer(); exit;
}

elseif ($shd_view==='news_edit'){
  $id=intval($_GET['id']??0);
  $item=shd_one($shd_link,$id);
  $cats=shd_cats($shd_link);
  shd_header('تعديل خبر');
  if(!$item){ echo '<div class="card shd-card p-3">غير موجود</div>'; shd_footer(); exit; }
  if($shd_msg) echo '<div class="alert alert-success">'.$shd_msg.'</div>'; ?>
  <div class="card shd-card p-3">
    <form method="post" enctype="multipart/form-data" class="row g-3" action="?p=news_edit">
      <?php echo shd_csrf(); ?>
      <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
      <input type="hidden" name="im_keep" value="<?php echo htmlspecialchars($item['image_path']); ?>">
      <div class="col-12"><label class="form-label">العنوان</label><input class="form-control" name="ti" required value="<?php echo htmlspecialchars($item['title']); ?>"></div>
      <div class="col-12"><label class="form-label">الفئة</label>
        <select class="form-select" name="ca" required>
          <?php foreach($cats as $c){ $sel = $c['id']==$item['category_id']?'selected':''; echo '<option '.$sel.' value="'.$c['id'].'">'.htmlspecialchars($c['cat_name']).'</option>'; } ?>
        </select>
      </div>
      <div class="col-12"><label class="form-label">تفاصيل الخبر</label><textarea class="form-control" name="de" rows="6" required><?php echo htmlspecialchars($item['details']); ?></textarea></div>
      <div class="col-12"><label class="form-label">صورة الخبر</label><input class="form-control" type="file" name="im" accept="image/*"></div>
      <?php if($item['image_path']) echo '<img class="shd-thumb mt-2" src="'.htmlspecialchars($item['image_path']).'">'; ?>
      <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-save"></i> حفظ التعديلات</button></div>
    </form>
  </div>
<?php shd_footer(); exit; }

else{
  shd_header('shahd'); echo '<div class="card shd-card p-3">لم يتم العثور على الصفحة</div>'; shd_footer(); exit;
}
