<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
require_post();
verify_csrf();
$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:null;
$name=post_string('name',120);
$email=mb_strtolower(post_string('email',190));
$password=(string)($_POST['password']??'');
$role=post_string('role');
$active=isset($_POST['is_active'])?1:0;
if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['admin','patron','employe'],true)){
    flash('danger','Vérifiez le nom, l’e-mail et le rôle.');redirect('pages/users.php'.($id?'?edit_id='.$id:''));
}
if(!$id&&strlen($password)<9){flash('danger','Le mot de passe doit contenir au moins 9 caractères.');redirect('pages/users.php');}
if($id===(int)current_user()['id']&&!$active){flash('danger','Vous ne pouvez pas désactiver votre propre compte.');redirect('pages/users.php?edit_id='.$id);}
try{
    if($id){
        if($password!==''){
            if(strlen($password)<9){flash('danger','Le mot de passe doit contenir au moins 9 caractères.');redirect('pages/users.php?edit_id='.$id);}
            $stmt=db()->prepare('UPDATE users SET name=?,email=?,password_hash=?,role=?,is_active=? WHERE id=?');
            $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,$active,$id]);
        }else{
            $stmt=db()->prepare('UPDATE users SET name=?,email=?,role=?,is_active=? WHERE id=?');
            $stmt->execute([$name,$email,$role,$active,$id]);
        }
        if($id===(int)current_user()['id']){$_SESSION['user']['name']=$name;$_SESSION['user']['email']=$email;$_SESSION['user']['role']=$role;}
        log_action('user',$id,'mise_a_jour','Compte '.$email.' mis à jour');flash('success','Le compte a été mis à jour.');
    }else{
        $stmt=db()->prepare('INSERT INTO users (name,email,password_hash,role,is_active) VALUES (?,?,?,?,?)');
        $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,$active]);$id=(int)db()->lastInsertId();
        log_action('user',$id,'creation','Compte '.$email.' créé');flash('success','Le compte a été créé.');
    }
}catch(PDOException $exception){flash('danger',$exception->getCode()==='23000'?'Cette adresse e-mail est déjà utilisée.':'Impossible d’enregistrer le compte.');}
redirect('pages/users.php');
