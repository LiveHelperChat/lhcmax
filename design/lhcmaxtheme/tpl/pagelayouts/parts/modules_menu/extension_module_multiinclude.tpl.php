<?php if (erLhcoreClassUser::instance()->hasAccessTo('lhmax','use_admin')) : ?>
<li class="nav-item"><a class="nav-link" href="<?php echo erLhcoreClassDesign::baseurl('max/settings')?>"><i class="material-icons">chat</i><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('pagelayout/pagelayout','Max settings');?></a></li>
<?php endif; ?>
