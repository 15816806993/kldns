<?php
require_once('inc/common.php');
if(!isset($loginuser['uid'])){
	exit("<script>window.location.href='/';</script>");
}

$action = isset($_POST['action'])?$_POST['action']:null;
//添加纪录
if($action == 'addrecord'){
	$domain_id = trim(getRequest('domain_id','post'));
	$name = trim(getRequest('name','post'));
	$type = trim(getRequest('type','post'));
	$value = trim(getRequest('value','post'));
	$code=getSafe(getRequest('code','post'));

	$stmt = $db->prepare('SELECT dns,name FROM `kldns_domains` WHERE domain_id=:id and (allow_uid=0 or allow_uid=:uid) limit 1');//查找是那个解析平台的域名
	$stmt->execute(array(':id'=>$domain_id,':uid'=>$loginuser['uid']));
	if(strlen($code)!=4 || !isset($_COOKIE['verification']) || md5(strtolower($code))!==$_COOKIE['verification']){
		$errorMsg = '验证码不正确！';
	}elseif (!$row=$stmt->fetch(PDO::FETCH_ASSOC)) {
		$errorMsg = '域名不存在';
	}else{
		setCookie('verification',null,-1,'/');//销毁验证码
		if(config('allowNum')<0){
			$errorMsg = '管理员已停止用户自助解析功能！';
		}elseif(config('allowNum')!=0){
			//查询用户已添加纪录数
			$stmt=$db->prepare('SELECT record_id FROM `kldns_records` where uid=:uid');//获取记录总数
			$stmt->execute(array('uid'=>$loginuser['uid']));
			if($stmt->rowCount() >= config('allowNum')){
				$errorMsg = '你的解析记录数已超过最大限额：'.config('allowNum');
			}
		}
		if(!isset($errorMsg)){
			$dnsApi = Dnsapi::getApi($row['dns']);
			if($ret = $dnsApi->addRecord($domain_id,$name,$type,$value,$row['name'])){
				$stmt = $db->prepare('INSERT INTO `kldns_records` (`record_id`, `uid`, `domain_id`, `name`, `type`, `value`, `updatetime`) VALUES (:record_id, :uid, :domain_id, :name, :type, :value, NOW())');
				if(!$stmt->execute(array(':record_id'=>$ret['record_id'],':uid'=>$loginuser['uid'],':domain_id'=>$domain_id,':name'=>$ret['name'],':type'=>$type,':value'=>$value))){
					$errorMsg = '添加成功，保存数据库失败！';
				}
			}else{
				$errorMsg = $dnsApi->errorMsg;
			}
		}
	}

}

//获取用户记录列表
$query = $db->prepare('SELECT a.*,b.name as domain,b.dns FROM `kldns_records` as a left join `kldns_domains` as b on b.domain_id=a.domain_id WHERE a.`uid`=:uid');
$query->execute(array(':uid'=>$loginuser['uid']));
$records = $query->fetchAll(PDO::FETCH_ASSOC);
$query = $db->prepare('SELECT * FROM kldns_domains where allow_uid=0 or allow_uid=:uid');
$query->execute(array(':uid'=>$loginuser['uid']));
$domains = $query->fetchAll(PDO::FETCH_ASSOC);

$title='域名控制台';//本页标题
require_once('head.php');
?>
			<div class="row">
				<div class="col-xs-12">
					<pre><h4>域名控制台<a href="logout.php" class="dns-btn btn-warning" style="float: right;">退出</a></h4></pre>
				</div>
				<div class="col-xs-12">
					<div class="panel panel-info">
						<div class="panel-heading">
							<ul class="nav nav-tabs">
								<li class="col-xs-6 text-center active"><a href="#list" data-toggle="tab">记录列表</a></li>
								<li class="col-xs-6 text-center"><a href="#add" data-toggle="tab" id="addtab">添加解析</a></li>
							</ul>
						</div>
						<div class="panel-body tab-content">
							<div class="table-responsive tab-pane fade in active" id="list">
								<table class="table table-striped table-bordered bootstrap-datatable datatable">
									<thead>
										<tr>
										  <th>域名</th>
										  <th>类型</th>
										  <th>记录值</th>
										  <th class="text-right">操作</th>
										</tr>
									</thead>
									<tbody>
									<?php
									if($records){
										foreach($records as $row){
											$dnsApi = Dnsapi::getApi($row['dns']);
											if($record = $dnsApi->getRecordInfo($row['domain_id'],$row['record_id'])){
												echo '<tr id="Record_'.$record['record_id'].'"><td>'.$record['name'].'.'.$row['domain'].'</td><td>'.$record['type'].'</td><td>'.$record['value'].'</td><td align="right"><a href="update.php?id='.$record['record_id'].'" class="dns-btn btn-success"><span class="glyphicon glyphicon-edit"></span></a>&nbsp;&nbsp;<span class="dns-btn btn-warning delRecord" record_id="'.$record['record_id'].'"><span class="glyphicon glyphicon-trash"></span></span></td></tr>';
											}else{
												//如何记录已不存在，删除数据库里的记录
												$db->exec("DELETE FROM `kldns_records` WHERE (`record_id`='".$row['record_id']."')");
											}
										}
									}
									?>
									</tbody>
								</table>
							</div>
							<div class="tab-pane fade" id="add">
								<?php
								if(!empty($errorMsg)){
									echo'<script type="text/javascript">$("#addtab").click();</script>';
									echo '<div class="alert alert-danger text-center" role="alert">'.$errorMsg.'</div>';
								}
								?>
								<form class="form-horizontal" action="#" method="post">
									<input type="hidden" name="action" value="addrecord">
									<div class="form-group">
										<label for="inputEmail3" class="col-sm-2 control-label">域名</label>
										<div class="col-sm-10">
											<select name="domain_id" class="form-control" size="1">
											<?php
											if($domains){
												foreach($domains as $row){
													echo'<option value="'.$row['domain_id'].'">'.$row['name'].'</option>';
												}
											}
											?>
											</select>
										</div>
									</div>
									<div class="form-group">
										<label class="col-sm-2 control-label">记录</label>
										<div class="col-sm-10">
											<input type="text" name="name" class="form-control" placeholder="二级前缀">
										</div>
									</div>
									<div class="form-group">
										<label for="inputEmail3" class="col-sm-2 control-label">类型</label>
										<div class="col-sm-10">
											<select name="type" class="form-control" size="1">
												<option value="A">A记录</option>
												<option value="CNAME">CANME记录</option>
											</select>
										</div>
									</div>
									<div class="form-group">
										<label class="col-sm-2 control-label">记录值</label>
										<div class="col-sm-10">
											<input type="text" name="value" class="form-control" placeholder="127.0.0.1">
										</div>
									</div>
									<div class="form-group">
										<label class="col-sm-2 control-label">验证码</label>
										<label class="col-sm-2 control-label"><img src="/code.php" onclick="this.src='/code.php?'+Math.random();" title="点击更换验证码"></label>
										<div class="col-sm-8">
											<input type="text" name="code" class="form-control">
										</div>
									</div>
									<div class="form-group">	
  										<div class="col-sm-offset-2 col-sm-10">
											<button type="submit" class="btn btn-success btn-block">添加记录</button>
										</div>
									</div>
								</form>
							</div>
						</div>
					</div>
				</div>
                
            </div>

<script type="text/javascript">
function loadScript(c) {
	var a = document.createElement("script");
	a.onload = a.onreadystatechange = function() {
		if (!this.readyState || this.readyState === "loaded" || this.readyState === "complete") {
			a.onload = a.onreadystatechange = null;
			if (a.parentNode) {
				a.parentNode.removeChild(a)
			}
		}
	};
	a.src = c;
	document.getElementsByTagName("head")[0].appendChild(a)
}
$(function () {  
	$(document).on("click",".delRecord",function(){
		var record_id=$(this).attr('record_id');
		var url="/ajax.php?action=delrecord&record_id="+record_id;
		loadScript(url);
	});
}); 
</script>

<?php require_once('foot.php');?>