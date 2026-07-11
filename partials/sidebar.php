<?php $u=bo_user(); $kpiType=$_GET['type']??''; $finView=$_GET['view']??''; ?>
<aside class="sidebar" id="sidebar"><div class="sb-top"><div class="profile-card"><div class="avatar">BO</div><div class="p-text"><div class="p-title">Back Office</div><div class="p-sub"><?=e($u['role_key']??'viewer')?></div></div></div></div><nav class="nav">
<div class="group-label">Utama</div>
<a class="<?=active_page('dashboard')?>" href="?p=dashboard"><span class="mi">⌂</span><span class="label">Dashboard</span></a>
<a class="<?=active_page('products')?>" href="?p=products"><span class="mi">▣</span><span class="label">Master Produk</span></a>
<a class="<?=active_page('inventory')?>" href="?p=inventory"><span class="mi">▤</span><span class="label">Inventory</span></a>
<a class="<?=active_page('production')?>" href="?p=production"><span class="mi">⚙</span><span class="label">Produksi</span></a>
<a class="<?=active_page('distribution')?>" href="?p=distribution"><span class="mi">⇄</span><span class="label">Distribusi</span></a>
<a class="<?=active_page('sales')?>" href="?p=sales"><span class="mi">₹</span><span class="label">Penjualan</span></a>
<div class="group-label">SDM</div>
<a class="<?=active_page('employees')?>" href="?p=employees"><span class="mi">👥</span><span class="label">Pegawai</span></a>
<details class="nav-sub" <?=($_GET['p']??'')==='kpi'?'open':''?>><summary><span class="mi">◎</span><span class="label">KPI Pegawai</span></summary><a class="<?=($_GET['p']??'')==='kpi'&&$kpiType==='store'?'active':''?>" href="?p=kpi&type=store"><span class="mi">↳</span><span class="label">KPI Pegawai Toko</span></a><a class="<?=($_GET['p']??'')==='kpi'&&$kpiType==='dapur'?'active':''?>" href="?p=kpi&type=dapur"><span class="mi">↳</span><span class="label">KPI Pegawai Dapur</span></a></details>
<div class="group-label">Keuangan</div>
<details class="nav-sub" <?=($_GET['p']??'')==='finance'?'open':''?>><summary><span class="mi">◫</span><span class="label">Keuangan</span></summary>
<?php foreach(['summary'=>'Ringkasan','purchases'=>'Pembelian','expenses'=>'Pengeluaran','payments'=>'Permintaan Pembayaran','payroll'=>'Penggajian','profit'=>'Laba Rugi','cashflow'=>'Arus Kas'] as $key=>$label): ?><a class="<?=($_GET['p']??'')==='finance'&&$finView===$key?'active':''?>" href="?p=finance&view=<?=$key?>"><span class="mi">↳</span><span class="label"><?=e($label)?></span></a><?php endforeach; ?>
<?php if(in_array(strtolower((string)($u['role_key']??'')),['owner','admin'],true)): ?><a class="<?=($_GET['p']??'')==='finance'&&$finView==='settings'?'active':''?>" href="?p=finance&view=settings"><span class="mi">↳</span><span class="label">Setting Jenis</span></a><?php endif; ?>
</details>
<div class="group-label">Sistem</div>
<a class="<?=active_page('integration')?>" href="?p=integration"><span class="mi">◈</span><span class="label">Integrasi API</span></a><?php if(in_array($u['role_key']??'', ['owner','admin'], true)): ?><a class="<?=active_page('users')?>" href="?p=users"><span class="mi">◉</span><span class="label">Admin User</span></a><?php endif; ?><a class="<?=active_page('settings')?>" href="?p=settings"><span class="mi">⚙</span><span class="label">Pengaturan</span></a></nav></aside>
