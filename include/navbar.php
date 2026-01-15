<?php
// ==== helpers + avatar loader ====

// Safe HTML escape
if (!function_exists('h')) {
    function h($s){
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Logged-in username
if (!function_exists('auth_username')) {
    function auth_username() {
        if (empty($_SESSION['auth_user'])) return '';
        $u = $_SESSION['auth_user'];

        return (string)(
            isset($u['Username']) ? $u['Username'] :
            (isset($u['username']) ? $u['username'] : '')
        );
    }
}

/**
 * লগইন করা user-এর avatar data URL (বা local default avatar) রিটার্ন করবে
 */
if (!function_exists('auth_avatar_src')) {
    function auth_avatar_src(PDO $conn) {
        // LOCAL default image (project root ধরে)
        // /assets/img/avatar-default.png ফাইলটা নিজে রাখবে
        $defaultAvatar = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBw8PDw8OEA4ODw0PDw4ODw4NDQ8PDw8QFREWFhURFRUYHSggGBomGxYVITEhJSkrLi4uFx8zODMtNyg5Oi0BCgoKDg0OGxAQGCslHSUrLS0tKy0tKy0tLS0tLS0tLS0tLS0rLS0rLS0tLS0tLS0tKystLS0tKy0tLSstLS0tLf/AABEIAOkA2AMBEQACEQEDEQH/xAAcAAEBAQACAwEAAAAAAAAAAAAAAQIFBgMEBwj/xAA/EAACAgEBAwgHBQUJAQAAAAAAAQIDEQQFEiEGEzFBUVNhkQcUFiJxgaEjUnKS0TIzYrHBFUJDgqKywuHwc//EABoBAQACAwEAAAAAAAAAAAAAAAADBAECBQb/xAAoEQEAAgEDBAEFAAMBAAAAAAAAAQMCESFRBAUVMRITIjJBcVKBkTP/2gAMAwEAAhEDEQA/APsoAAAAAAAAAAAoDADADADADADAEAAAAAAAAAAAAAAAAAAACgMAXAFwAwAwAwAwAAmAGAJgABAAAAAAAAAAAAAAALgCoC4AuAGAKBxG2OU2g0T3dTrKKZ/cnNb/AOVcQPHszlbs3VPdo12msl91WxjLyYHLRvrctxWQc/uqcXLyzkDcZJ8U01x4pprg8MCgMATAEwBGgIAAAAAAAAAAAAGkgLgCgUAB1fldy80Gy/s7pys1LjvR01K3rMds30QXxfHqTMD4rtH0n7Zuk2tZ6vBttV6eqqKiupb0ouT8wOpXWysnKycpTsnJynObcpSk+ltvpYGMAahZKL3oylGa6Jwk4yXwkuIHO8nuWe0dn7sdPqpqmMnL1e1Kyh5eZe6+Ky23lNAfe9l8vNnW6TT6m/V6bSyurUnVdfCM4y6JRw3npTMjsGz9dTqa43UW13Uyzu2VTU4SxweGgPYYEAjQEaAgEAAAAAAAAoFwBUBQKBQOi+k/l1HZlLoolB7Rtj7kX73MQeVz0o/J4XW/BAfnm+6dk5WWTlOycnKc5velOT6ZN9ZgYAAAAACYXYsgfQvR56SdRop0aTUyVmzvdqWYpWaaPQpRa6YrrT6uKfDiH6Bi00mmmmk01xTXUzIAQCNARoCAQAAAAAAGkgKkBQKBQK/Ho634AflHldtN6vaGs1L/AMW+e7nqri9yC+G7GJgcSADIGAAAAAGgP076M9oPU7I0Nsnmaq5qT63KqTg/9pkdmAgEYEYEaAyAAAAAFQGkBQKBQKBxfKrW+r6DWX91prp/6GB+Tl5+Jgdv9HXJFbTum7XJaSjd53ce7Kc5Z3a0+rgm2+zHaaWZ/Fvhhq+q6n0ebIshueo118MKymdkLV472ePzyQfVyTfTh1+70P6VvMNZqYrscapfXBv9aWv0oezofRLs+H72zU3+DsVS/wBCz9TWbZZiqHt6r0XbJmsRqupf3q9TZLzU20Itlma4eCPon2Zji9XJ9vP7v8kPqyfTh1Plt6NZaSqWq0k53Uw962qxLnYQ+/Fr9pLrXSSY267Sjyr09PnZKifoL0FWuWyN1/4er1MF8G4z/wCRkfQgAGWBGBGBlgQAAAoFQGgKgKBQKB1X0pya2LtDHXQ4+ckgPzIzA/Qvo42L6ls6mDWLrs6m7t35pYXyior5FWzLWVqvHSHZzRuAAAADM4ppprMWmmn1p8GhGzExq/M/KTZ3qmt1el6qb7IR/A/eh/pcS3jOsaquW0vuPoKocdkKXe6vVTXwTjX/AMGbtX0ICAQCARgZYEAgACoDSA0BUBUBQKB1f0oQzsbaPhppvy4gfn3kRsj13aOm07/d7/PW/wDyr96S+eEv8xplOmOrbGNctH6Q/wDYKi2AAAAAAMD4R6YNMobVlJL99p9PY/GS3oN+UEW6vWirZG77B6IobuxNF4xtl+a6bz9SRo7iBAIwIBGBlgZYEAAaQGkBUBoCgUABxnKjQes6HV6fvtPdWvi4PAHwP0ORf9q4axJaTU5T6nvVpois/FJX+T7qVlkAAAAACGB8V9NtMo6+mz+7PSJR+MLJb3+6JZq9K9vt9s5F7P8AVtnaLTvpr01Sfx3U39WTInMgAIBGBlgRgZYGQKgKgNIDQFQFAoFA9fW3OMVjpbxnsI7MvjDfDHWXyLk3yclpeUerkv3L01upqx1xvsj7r+ElNfJEeWXyxSY4/HJ9KIUwAAAAAADpHL7Yy1ms2PW/2Xqbeca7qEOdkvnuY+ZJXlpEorMdZh9J0Nzkmn0p/TsJa8tUdmOkvZJf0j9oBAIBGBlgZYEYBAaQGkBUBQKBQKB62vhmGfuvJFbGuKSudMnVo6fG0pW9UtDCCfjG+Tf+5EGv2p9PucuatgAAAAAIB6l+kctRRdwxVC9eO9NRS+ikZidmNN9XO7OhiOe1liqNtVeyd3tEqNAIBAIBGBlgZYBAaQGkBUBoCgAKBm6OYtdqZrlGsMxOkuFx5lOdltQyAAAAAAAg/Z+nM6eOIRXgW8I0hUynWWzdqAQCAZAjAywMsAgNIDSAqA0BUBUAAoHoa7Txit5dLfHsK9mERGqavOZnR6RCnAAAAAAAe3oaIyW8+p/Imrwid5Q2ZzGzkSwgQAwIwIBlgRgZYGWAQGkBpAVAaAoFQACgeDWQzCXauPkaWR9rbCd3FFRbAAAAAAAcpoYYgvHj+haqjZVsnd7BI0QAwIBAMsCMDLAywCA0gNICoCgUCgUCgAOJ1dO5LwfFfoVLMdJWa8tYeE0SAAAAA8umq35Y6lxfwNsMdZaZzpDlkW1X2pkQCAQCARgRgZYGWAQFQGkBoCoCgUCgAKBxe2nh1/5v6Fa9PRHt6ldmfiQRKeYbNmAABiyaXxNZlmIezsZ5lP4R/myaj2iu9OWLStCAAIBAIwIwMsCMDLAgGkBpAUDQFAoACgUDidtvjBeEv6FXqJ3hZo9S40rRssS8sLu02iWujfPLx8jPyY0Yld2ebMasxDxM1ls5DYr9+f4V/MsUTugvjZzBbVUAgBgQCAZYEYGWBkABUBpAaAqAoFApgnYMxuS8Gq1ca1xeX1RXSyPKyMYb44TlLhdRfKyW8/gkuhIp55zlOq3hjGLxGjcAAAAHkoulCW9Hp+jXYbY5fGdWuWHyjRzWl1kbFw4S64vp/wCy7hZEqeeE4vYJGgBAaoBGBGBlgZYEAAUCoDSAqAoEstjFb0pKMV0uTSS+bMxjM+muWeOMby4HX8stFVwVjul2UreX5ugt19Bfnvpo513denrjSJ1Z0fKRaqG9V7mOEovjOL8Sj1ldtE6TGzo9DfV1OOse+EbzxfF9r4nOnSd3SiJQMgAAAAAACePB9ojWPRMRLeq5QrTQ3rXvLojHPvyfYi/0mFl2WkR/tzutuq6bD5ZT/pNBy00VvCU3TJ9VyxHP4uj+R0bO33YfrWHMp7tRn+9Jc/VdGaUoSjOL6JRkpJ/NFOcZx9uhjnjlG0tMw3j0jAjAywIBAAACoDSEGujjdqbe02lyrLVv93D3p+S6CxT0tlv4wqdR1tNP5Tu6jtLl5dPMaK41R+/Z79j+XQvqdSrteMfnOv8AHDv71Zl/5xo6xrdfde822zsf8UuHl0HQrpwr/GHJt6iy2dc8tXrEqB59Hq50zU65bsl5NdjXWiG/p8LsfjmsdN1NlGfywl3PZG3a78Rliu77jfCX4X/Q8p1nbLKJ1x3xe16Du9fUxpltk5Y5mnLr+/QGQAAAD9E6xsDbgcXtfbVenTjwnb1Vp9D/AIn1HR6Lt1l8/KY0xcnr+619NExG+Tpeu1tl89+yWX0JdCiuxI9X03TYU4/HF4rqeqt6jL5WS8BY/as8+j1ttL3qrJ1v+CTS+a6GRZ1YZx90apa+osrnXHJ2bZvLu+GFfCN0fvR9yz9H9Dn29swn8JdajvWeO2caw7dsrlDpdVhQsSm/8Oz3Z/BLr+RzLelsq/KNnbo6+q78Z3coytsu6ssH8QAAAAAfrV0HlXyqslZPT6ebhVBuE7IPEpyXSk+pZ7Dt9H0OMYxnnGs8PM9x7llOU4VzpDqLfX0vtfFs6unDhTMzvKDTRgMgAAuTExr7ZidN4lzGzuUV1WIy+1guqb95fCRy+q7TVdvjtLtdJ3u+nbP7oc/peUmmn0ydUuyxYX5lwOJd2m/D8Y1/j0PT976az3On9cnVqK5cYzhJeEkyjn09uHvGXSw6qnOPtyh5MkfxnhL88eWLL4R4ynCK/ikkb40WZesUWfU04fllEON1XKLTV9E3Y+ytZX5nwLtPab7Pcaf1zr+9dNV6nX+Ov7R5SXW5jD7GH8L99r8XV8jt9N2murfLeXnur75bdth9sOGydaIiI2cScpn3KGWAM67AYAyGJjWNCMpidYdq5L8qrKpwpvm50SaipzeZVt9Dz1x+PQczq+hxnGcsI3drt/c88Mowz3h9EOE9TE67oAAAAOL5TbQ9W0tlieJtbkPxS4Z+XFlnpKvqWRCl11/0qJy/b5MeniNPTxMzM7yGWAAAAAABj0A1ZVcOjh8DE44z7hmM8o9S1zsvvS/MzX6df+Mf8b/Xs/yn/rLeenj8eJtGGMfqGuWeWXuZQzs0DIAAAAAAAD2zG3p9U5JbReo0kHJ5sr+yn25j0PyweY6yr6dkxy9p22/61ET+4cyVV8AAAOi+kfV5nRp0+EYytkvGXCP0UvM7Xa69pzeb75brOODph13nwAAAAAAAAAAAAAZAwAAAAAAAAdu9HWr3braW+FkFNfig/wBH9Dk90r1xjLh3eyW6WTXy7+cR6cAAAOvbX5KV6q6V87rFKSit2KjhJLGEXaOuyqx+MRDmdT2zC/Oc5l6fsFR393lAm8nZxCv4Srk9gqO/u8oDydnEHhKuT2Co7+7ygPJ2cQeEq5PYKjv7vKA8nZxB4SrlfYGjv7vKA8nZxB4SrmT2Bo7+7ygPJ2cQeEq5PYGjv7vKA8nZxB4SrmV9gaO/u/LEeTs4g8JVzJ7AUd/d5QHk7OIPCVcyewFHf3eUB5OziDwlXMnsBR393lAeTs4g8JVzJ7AUd/d5QHk7OIPCVcyewFHf3eUB5OziDwlXMnsBR393lEeTs4g8JVzKewNHf3flgPJ2cQeEq5k9gaO/u8oDydnEHhKuT2Bo7+7ygPJ2cQeEq5T2Co7+7yiPJ2cQeEq5PYKjv7vKA8nZxB4Srk9gqO/u8oDydnEHhKuT2Co7+7ygPKWcQeEq5e1svkjVproXwutcoN8Go4eVhp+ZHb1+duPwmE/T9qwoz+cTu7GUHUAAAAAAAAAFAZAuQLkBkBkBkBkBkBkCZAmQAEAAAAAAAAAAAAAAAAAADIFyAyAyAyAyAyAyBMgAAAAAAAAAAD28AMAMAMAMAMAevLWVq2FP9+cLLI9G7iDinx7ffQG9RqK64785KMU4rL7XJRX1aQCrUVz3lGUXuS3J/wAMsJ4+qA3vLw+gGKtRXPe3ZJ7k5Vy8JLpQGNXq4Vbm9/fsrqWFl705YjnwyB52108MdoEyvACprwAxqLoVwlZNqMIRcpSlwSiulgce9v6VOtOVijaoyhZLS6hUyUob6+1cNxe7x6QC2/pWk1KyTc+b3IaXUyt3t1S41qG8lutPexjDTyB5P7b0u9ZF2qLrU5Sc4ThDEMKeJySjJxbSaTeM8QJ/bml+xfOpq+FdlbULHHcs/YlJ4xBSfBb2MvgB45cotGoWWSvjGFSTlKcZwTTbUZQyvfi2mk45Tw8AcqgGAGAGAGAGAKAAAAAGbFlNdqa6wOow5Jzde5KOmjGFOqrprTlNVSmq1XNzcE5NbknvNZWV0viBm/kvfOPNy9VnCvn5V845vnZWamF/vpwagvdccre6c+AF13JSc9/dq0qg9Rz/ADULZ0qxSpcHGco15W423F4ecv8AZfEDzankxJxtcIaeV89TG6FlspPciqY1xcsxfOYak918HnpT4gY1XJmx87u1aKandfZu2b0Y2c7HG/YlB4lBt46c5fGIGZ8lLnCVLsry7KZvXKU46yai4Nxl7vDG68e8856usOQ1eyrp06et16SS07hJ0ynNae/EJRaa3HupNqS4S4r5gcfbyVtnPj6soqcpSmt9z1EZWQlzVi3eEYqLS4yzw/Z6w5DYewHprZWLmlGS1SarTTcZ6qVlKfDohW1HwxhcAPc1eyFZXVVG66uNM1NNOFrm45wp84pb2Hh/FID09n7Gurhp4zthJ6XRxppbW9F3uG7O2UcLKSSiuvEpdoHqWbB1Eo2vd08bbrJTTjqdQvVm64xdsJqOZtuOdx7qWEsgeTRbD1FN8rvsLZJXtTnZZGWolZKMoqcdxxq3d1cY72exAY0XJuzmtFXbzcXp6aatRzN9ko6hUJc1FpxS3XL3n1rGOOcgNTsnW20a2Flejeo1NU6IWLVW7ldcozgko8z7qipN447zb4rqDs2ncnGLnGMZ4W9GE3OKfYpNJteOEB5AAAAAAAAAAAAAgDtAfp+gD/oCMCoCL/3mBUAAAGAAMA/0AAUAAAAAAH//2Q==';

        // কেউ logged-in না থাকলে সরাসরি default
        if (empty($_SESSION['auth_user'])) {
            return $defaultAvatar;
        }

        $u   = $_SESSION['auth_user'];
        $uid = 0;

        if (isset($u['UserID'])) {
            $uid = (int)$u['UserID'];
        } elseif (isset($u['id'])) {
            $uid = (int)$u['id'];
        }

        if ($uid <= 0) return $defaultAvatar;

        try {
            $st = $conn->prepare("SELECT Avatar FROM dbo.Users WHERE UserID = :id");
            $st->execute(array(':id' => $uid));
            $row = $st->fetch(PDO::FETCH_ASSOC);

            // Avatar column খালি থাকলে default image
            if (!$row || empty($row['Avatar'])) {
                return $defaultAvatar;
            }

            $raw = $row['Avatar'];
            $bin = is_resource($raw) ? stream_get_contents($raw) : (string)$raw;
            if ($bin === '') return $defaultAvatar;

            // mime detect
            $mime = 'image/jpeg';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $det = finfo_buffer($finfo, $bin);
                    finfo_close($finfo);
                    if ($det) $mime = $det;
                }
            }
            if (strpos($mime, 'image/') !== 0) {
                $mime = 'image/jpeg';
            }

            // data URL বানিয়ে রিটার্ন (এটা local DB data, external না)
            return 'data:' . $mime . ';base64,' . base64_encode($bin);
        } catch (Exception $e) {
            // error হলেও default local image
            return $defaultAvatar;
        }
    }
}

// navbar image এর জন্য avatar নেয়া হচ্ছে
$navAvatarSrc = auth_avatar_src($conn);
?>

<style>
  /* Navbar avatar design: গোল + border + একটাই subtle shadow */
  .nav-profile-avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;                       /* গোল */
      object-fit: cover;                        /* crop ঠিক রাখে */

      border: 2px solid #64C5B1;                /* পাতলা border */
      box-shadow: 0 3px 8px rgba(0, 0, 0, .18); /* একটাই soft shadow */

      background-color: #f9fafb;                /* transparent hole clean bg */
      cursor: pointer;
  }

  .profile_info {
      position: relative;
  }
</style>

<div class="container-fluid g-0">
    <div class="row">
        <div class="col-lg-12 p-0">
            <div class="header_iner d-flex align-items-center" style="background: linear-gradient(135deg, #667eea, #764ba2); border-radius:12px; height: 100px; padding: 0 20px;">
                
                <!-- Sidebar icon (for mobile) -->
                <div class="sidebar_icon d-lg-none">
                    <i class="ti-menu" style="color:white; font-size:24px;"></i>
                </div>

                <!-- Centered Heading -->
                <div style="flex: 1; text-align: center;">
                    <h1 style="margin: 0; font-size: clamp(24px, 5vw, 36px); font-weight: 700; color: white;">
                        Welcome to PRF Leave Portal
                    </h1>
                </div>

                <!-- Profile Avatar & Dropdown -->
                <div class="header_right d-flex align-items-center">
                    <div class="profile_info position-relative">
                        <img
                            class="nav-profile-avatar rounded-circle"
                            src="<?= h($navAvatarSrc); ?>"
                            alt="Avatar"
                            style="width: 48px; height:48px; object-fit:cover; cursor:pointer;">

                        <div class="profile_info_iner position-absolute" style="right:0; top:60px; background:white; border-radius:8px; box-shadow:0 4px 6px rgba(0,0,0,0.1); display:none;">
                            <div class="profile_author_name p-2 border-bottom">
                                <!-- <p class="mb-0"><?= h(auth_username() !== '' ? auth_username() : 'Guest') ?></p> -->
                            </div>
                            <div class="profile_info_details p-2 d-flex flex-column">
                                <a href="<?= htmlspecialchars(url_to('./pages/account/profile.php')); ?>" class="mb-1 text-dark text-decoration-none">Change Password</a>
                                <a href="<?= htmlspecialchars(url_to('/logout.php')); ?>" class="text-dark text-decoration-none">
                                    <i class="fa-solid fa-right-from-bracket"></i> Log Out
                                </a>
                            </div>
                        </div>
                    </div><!-- /.profile_info -->
                </div><!-- /.header_right -->

            </div><!-- /.header_iner -->
        </div>
    </div>
</div>

<script>
    // Toggle profile dropdown on avatar click
    document.querySelectorAll('.nav-profile-avatar').forEach(avatar => {
        avatar.addEventListener('click', function() {
            const dropdown = this.nextElementSibling;
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });
    });

    // Click outside to close dropdown
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.profile_info_iner').forEach(drop => {
            if (!drop.parentElement.contains(e.target)) {
                drop.style.display = 'none';
            }
        });
    });
</script>

