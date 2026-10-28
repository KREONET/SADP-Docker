<div align="center">

# SADP-Docker

**Coolify기반 쉽게 앱을 배포하고 여러가지 위험을 방지위해 추가로 기능을 넣은 플랫폼**

**개인 사용자용 한국어 버전 (SADP-Docker-Personal)**

</div>

## 이 버전은 무엇이 다른가요?

이 저장소는 SADP-Docker([SADP-Docker](https://github.com/KREONET/SADP-Docker))를 **개인 사용자**가 집이나 연구실에서 쉽게 쓸 수 있도록 고친 버전입니다. 공인 IP나 도메인 인증 수단이 없고, 서버 몇 대를 내부망으로 묶어 쓰는 환경을 기준으로 했습니다.

**한국어 버전**
- 화면 문구를 한국어로 번역했습니다. 새로 추가한 기능의 문구도 모두 한국어로 나옵니다.
- 설치·사용 안내(이 README)와 개발자 문서([docs/ko](docs/ko/README.md))를 한국어로 제공합니다.

**추가한 기능**
- **내부망 서버 여러 대를 컨트롤러 하나로 묶기:** 내부망의 서버들을 등록해 앱을 나눠 배포하고, 외부 트래픽은 컨트롤러(SADP-Docker가 설치된 서버)의 프록시 하나로만 받습니다. 워커 서버에는 프록시가 돌지 않습니다. → [사용 방법](#내부망-서버-여러-대를-컨트롤러-하나로-묶기)
- **Cloudflare Tunnel로 도메인 공개하기:** DNS-01이나 TSIG처럼 도메인을 직접 인증할 수단이 없어도, 포트를 열지 않고 Cloudflare Tunnel로 도메인을 공개할 수 있습니다. → [사용 방법](#cloudflare-tunnel로-도메인-공개하기)

**기타 수정**
- 링크를 공유할 때 미리보기에 원본 Coolify 설명과 이미지 대신 서비스 이름만 나오도록 바꿨습니다.
- 저장소 주소와 GitHub Releases 링크를 이 저장소(`SADP-Docker-Personal`)로 바꿨습니다.

나머지 기능(앱별 로그, 통합 모니터링, 팀별 분리, 취약점 검사, OIDC 등)은 원본 SADP-Docker와 같습니다.

## 왜 SADP-Docker 따로 나누었나요?
정부기관이나 연구기관 연구소 같은 특수한 상황속에서는 많은 로그와 제약이 있을수 있습니다. 그래서 이러한 제약사항에서도 연구자들이
AI로 만든 프로그램 및 웹이나 오픈소스를 보다 격리된 환경에서 사용할수 있기 위해 기능을 추가를 해서 만들었습니다.

## 그리고 Coolify는 뭐에요?
Coolify는 Heroku, Netlify, Vercel을 대체할 수 있는 오픈 소스이자 자체 호스팅(self-hostable)이 가능한 솔루션입니다. 이를 통해 사용자는 자신의 하드웨어에서 서버, 애플리케이션, 데이터베이스를 직접 관리할 수 있으며, SSH 연결만 있으면 바로 이용 가능합니다.

VPS, 베어메탈 서버, 라즈베리 파이(Raspberry Pi) 등 SSH 연결을 지원하는 어떤 서버에서든 사용할 수 있습니다. Coolify는 인프라에 대한 통제권을 유지하면서도 클라우드 플랫폼이 제공하는 편리함을 누릴 수 있게 해줍니다.

모든 설정 정보는 사용자의 서버에 저장됩니다. 따라서 Coolify 사용을 중단하더라도 실행 중인 리소스는 계속 작동하며, 여전히 관리 가능한 상태로 유지됩니다.

## SADP-Docker는 어떤 기능을 제공해주나요?
저희는 정부기관에서 요구사항이나 보안을 위해서 아래와 같은 기능이 추가됨니다.
- **앱별 로그 기록:** 앱에서 통신되고있는 트래픽 정보나 앱에서 일어나는 로그를 수집을 할수 있습니다.
- **통합모니터링:** 중앙에 있는 관리자가 한눈에 관리를 할수 있음니다.
- **독립적인공간:** 원래는 독립적인 공간이 있을려면 서버를 분리해서 써야 하지만 여기는 공통된 서버에서 그룹별 및 사용자별로 분리가 됨니다.
- **패키지 취약점 검사:** tinvy라는 보안 스캐너를 이용해서 배포를 할때 패키지별로 검사를 합니다. 이상한게 발견되면 사용자나 레포에 직접적으로 알려줌니다.
- **OIDC지원:** 정부나 연구관에서 별도로 SSO을 사용한 경우 OIDC을 이용해 바로 로그인이 가능합니다.
- **내부망 서버 묶기:** 내부망의 여러 서버를 등록해 앱을 나눠 배포하고, 외부 트래픽은 컨트롤러의 프록시 하나로만 받습니다.
- **Cloudflare Tunnel:** 공인 IP나 도메인 인증 수단이 없어도 Cloudflare Tunnel로 도메인을 공개할 수 있습니다.

Coolify와 같이 아래와같은 기능은 공통적으로 제공됨니다.
----------------------------------------------------
- **모든 애플리케이션 배포:** Nixpacks, Railpack, Dockerfile, Docker Compose 또는 사전 빌드된 Docker 이미지를 사용하여 GitHub, GitLab, Bitbucket, Gitea 등에서 빌드 및 배포하세요.
- **데이터베이스 및 서비스 실행:** 관리형 데이터베이스와 300개 이상의 원클릭 서비스를 영구 스토리지 및 자동 생성된 자격 증명과 함께 배포하세요.
- **배포 자동화:** Git 푸시 시 배포, 풀 리퀘스트(PR) 미리보기 생성, 배포 웹훅 호출, 그리고 이전 애플리케이션 이미지로의 롤백 기능을 활용하세요.
- **네트워킹 관리:** 사용자 지정 도메인, 자동 HTTPS 인증서, 리버스 프록시, 상태 확인(health check) 및 컨테이너 네트워크를 설정하세요.
- **인프라 운영:** 다수의 서버 관리, 배포 및 런타임 로그 확인, 컨테이너 터미널 접속, 리소스 상태 모니터링을 수행하세요.
- **워크로드 보호:** 데이터베이스 및 스토리지 백업, 예약 작업, 환경 변수, 보안 정보(secrets), 알림 기능을 설정하세요.
- **워크플로 통합:** 대시보드, API, CLI, MCP 및 팀 단위의 접근 제어 기능을 통해 리소스를 관리하세요.

## 개발 환경 만들기

준비물은 Docker뿐입니다. PHP와 Node는 컨테이너 안에서 돌기 때문에 따로 설치하지 않아도 됩니다.

```bash
git clone https://github.com/KREONET/SADP-Docker-Personal.git
cd SADP-Docker-Personal

./scripts/dev start    # 개발 환경 시작
./scripts/dev demo     # 또는: 데모 데이터와 Loki/Grafana까지 함께 시작
./scripts/dev urls     # 접속 주소와 포트 확인 (main 브랜치는 http://localhost:8000)
```

- 데모 환경 로그인: `test@example.com` / `password` (플랫폼 관리자)
- 원격 서버에서 띄웠다면 SSH 터널로 접속합니다: `ssh -L 8000:localhost:8000 <서버>`
- 개발 인스턴스는 git 브랜치마다 따로 만들어집니다.

| 명령 | 하는 일 |
|---|---|
| `./scripts/dev stop` | 내리기 (데이터는 유지) |
| `./scripts/dev start` | 다시 올리기 |
| `./scripts/dev run` | 올리고 로그 보기, 종료하면 같이 내려감 |
| `./scripts/dev exec <명령>` | 앱 컨테이너 안에서 실행 (예: `./scripts/dev exec php artisan migrate`) |
| `./scripts/dev destroy <이름>` | 컨테이너, 볼륨, VM까지 삭제 (되돌릴 수 없음) |

테스트와 화면 수정 방법은 [한국어 개발자 가이드](docs/ko/README.md)를 참고하세요.

## 운영(본) 환경 만들기

SADP-Docker는 Coolify 공식 이미지가 아니라 **이 저장소에서 직접 빌드한 이미지**로 실행해야 합니다. 공식 설치 스크립트만 실행하면 SADP-Docker 기능이 없는 원본 Coolify가 설치됩니다.

**준비물:** Docker를 설치할 수 있는 Linux 서버 1대, root 또는 sudo 권한, 방화벽에서 `8000`(웹), `6001`(실시간), `6002`(터미널) 포트 허용

### 1. 기본 설치 (디렉터리, 비밀값, SSH 키 생성)

```bash
git clone https://github.com/KREONET/SADP-Docker-Personal.git /opt/SADP-Docker
cd /opt/SADP-Docker
sudo bash scripts/install.sh
```

설치를 시작하면 **"내부 회원가입 기능을 쓸까요? [Y/n]"** 하고 묻습니다.

| 답 | 결과 |
|---|---|
| `Y` (기본값) | 일반 설치. 처음 접속한 사람이 회원가입 화면에서 관리자 계정을 만듭니다. |
| `n` | 회원가입을 끄고 **root 계정만 무작위 비밀번호로 생성**합니다. 설치가 끝나면 화면에 이메일(`root@SADP-Docker.local`)과 비밀번호가 표시됩니다. root로 로그인한 뒤 **설정 → OAuth**에서 OIDC를 연동하면, 다른 사용자는 OIDC로 가입하고 로그인합니다. |

- 질문 없이 설치하려면 `INTERNAL_SIGNUP=true` 또는 `INTERNAL_SIGNUP=false`를 지정합니다. 예: `sudo INTERNAL_SIGNUP=false bash scripts/install.sh`
- root 계정 정보를 직접 정하려면 `ROOT_USERNAME`, `ROOT_USER_EMAIL`, `ROOT_USER_PASSWORD`도 함께 지정합니다. 비밀번호는 대문자, 소문자, 숫자, 특수문자를 모두 포함한 8자 이상이어야 합니다.
- root 비밀번호는 `/data/coolify/source/.env`에도 저장됩니다. 첫 로그인 후 비밀번호를 바꾸세요.

`/data/coolify/` 아래에 데이터 폴더와 `.env`(DB 비밀번호, APP_KEY 등)가 만들어지고, 일단 원본 Coolify가 실행됩니다. 다음 단계에서 SADP-Docker 이미지로 교체합니다.

> root 계정은 **SADP-Docker 이미지로 교체한 뒤** 처음 시작될 때 만들어집니다. 원본 Coolify 이미지도 같은 방식으로 root를 만들기 때문에, 1단계에서 이미 만들어질 수도 있습니다.

### 2. SADP-Docker 이미지 빌드 후 교체 (필수)

**이 단계를 하지 않으면 저장소에서 고친 내용이 하나도 반영되지 않습니다.** 1단계에서 실행된 것은 공식 Coolify 이미지입니다.

```bash
cd /opt/SADP-Docker
sudo bash scripts/SADP-Docker-deploy.sh
```

이 스크립트가 하는 일:
- 이 저장소의 코드로 `coollabsio/coolify:SADP-Docker` 이미지를 빌드합니다. 처음에는 몇 분 걸립니다.
- 저장소의 compose 파일을 `/data/coolify/source/`에 복사합니다.
- `.env`에 `LATEST_IMAGE=SADP-Docker`, `AUTOUPDATE=false`를 기록합니다. 그래서 재시작해도 SADP-Docker 이미지가 유지되고, 자동 업데이트가 공식 이미지로 덮어쓰지 않습니다.
- 컨테이너를 교체해 실행합니다. DB 마이그레이션은 컨테이너가 시작될 때 자동으로 실행됩니다.

교체가 끝나면 `http://<서버 IP>:8000`에 접속합니다. 내부 회원가입을 켰다면 첫 관리자 계정을 만들고(이 계정이 플랫폼 관리자가 됩니다), 껐다면 설치 끝에 표시된 root 계정으로 로그인합니다.

반영됐는지 확인하려면: `docker inspect coolify -f '{{.Config.Image}}'`가 `docker.io/coollabsio/coolify:SADP-Docker`여야 합니다.

### 3. 업데이트는 GitHub에서 확인

SADP-Docker에는 앱 안에서 업데이트하는 기능(Upgrade 버튼, 자동 업데이트)이 없습니다. 원본 Coolify의 업데이트 기능은 공식 Coolify 이미지를 받아 와서 SADP-Docker를 덮어쓰기 때문에 제거했습니다. 새 버전은 [GitHub Releases](https://github.com/KREONET/SADP-Docker-Personal/releases)에서 확인하고, 아래 **SADP-Docker 새 버전으로 올리기** 방법으로 올리세요.

### 서버 공유하기 (여러 팀이 같은 서버 사용)

원래 Coolify는 팀마다 서버를 따로 등록해야 합니다. SADP-Docker에서는 플랫폼 관리자가 서버 하나를 모든 팀과 공유할 수 있습니다.

1. 플랫폼 관리자(Root 팀 admin/owner)로 로그인합니다.
2. **서버 → 해당 서버 → 공유**에서 **모든 팀과 공유**를 켭니다.
3. 이제 다른 팀(OIDC로 가입한 사용자의 개인 팀 포함)이 새 리소스를 만들 때 이 서버를 고를 수 있습니다.

공유해도 서버 관리(프록시, 삭제, 서버 터미널 등)는 원래 팀의 관리자만 할 수 있습니다. 다른 팀은 자기 리소스와 자기 배포 기록, 로그만 봅니다. 컨테이너에 호스트 권한을 주는 설정(privileged, Docker 소켓·호스트 경로 마운트 등)은 원래대로 플랫폼 관리자만 넣을 수 있습니다. 공유 서버 위 컨테이너의 터미널 접속은 아직 플랫폼 관리자만 됩니다.

### 내부망 서버 여러 대를 컨트롤러 하나로 묶기

같은 내부망에 있는 서버 여러 대를 등록해서 앱을 나눠 배포하고, 외부 트래픽은 **컨트롤러(SADP-Docker가 설치된 서버)의 프록시 하나**로만 받는 방식입니다. 워커 서버에는 프록시(Caddy/Traefik)가 돌지 않습니다.

```
인터넷 ──▶ 컨트롤러 프록시(Caddy/Traefik, 도메인·HTTPS)
             ├─ 릴레이 ──▶ 워커 A 10.0.0.12:41000 (앱 1)
             └─ 릴레이 ──▶ 워커 B 10.0.0.13:41000 (앱 2)
```

1. 워커 서버를 평소처럼 **서버 → 추가**로 등록하고 검증합니다. 컨트롤러가 SSH로 접속할 수 있어야 합니다.
2. **서버 → 워커 → 프록시**에서 **컨트롤러를 통해 트래픽 받기**를 켜고, 워커의 **내부 IP**를 입력합니다. 워커의 프록시는 멈춥니다.
3. 워커의 앱은 다시 배포하고 서비스는 다시 시작합니다. 도메인이 있는 컨테이너는 워커의 내부 IP에만 `41000-48999` 포트로 공개됩니다.
4. 도메인의 DNS는 **컨트롤러**를 가리키게 합니다. 인증서(HTTP-01, DNS-01 와일드카드)도 컨트롤러 프록시에서 처리합니다.

- 컨트롤러는 라우팅되는 컨테이너마다 작은 릴레이 컨테이너(`SADP-Docker-relay-*`, nginx)를 띄웁니다. 릴레이는 앱의 프록시 라벨을 그대로 갖고 있어서 도메인, 리다이렉트, Basic Auth, 내부망 전용/로그인 전용 접근 제한이 그대로 동작합니다.
- 한 앱을 여러 워커에 배포하면(추가 서버) 릴레이가 워커들 사이에서 부하를 나눕니다. 이때 앱의 서버는 모두 컨트롤러 라우팅이거나 모두 아니어야 합니다.
- 롤링 업데이트 중에는 새 컨테이너가 헬스 체크를 통과한 뒤에 트래픽이 넘어갑니다.
- 워커의 `41000-48999` 포트는 컨트롤러만 접근할 수 있게 하고 인터넷에는 닫아 두세요.
- 컨트롤러의 **프록시** 페이지에서 라우팅되는 서버 목록을 보고 **라우트 동기화**를 누를 수 있습니다. 명령으로는 `php artisan SADP-Docker:sync-controller-ingress`입니다.
- 아직 안 되는 것: Swarm 서버, Raw Docker Compose 배포(포트를 직접 공개해야 함), 공개 포트를 쓰는 데이터베이스는 워커 자신의 IP로 열립니다.

### Cloudflare Tunnel로 도메인 공개하기

공인 IP나 열린 포트가 없고 DNS-01(Cloudflare/TSIG) 자격 증명도 쓸 수 없을 때, Cloudflare Tunnel로 도메인을 공개할 수 있습니다. cloudflared가 컨트롤러에서 Cloudflare로 나가는 연결을 열기 때문에 80/443 포트를 열 필요가 없습니다.

1. Cloudflare Zero Trust → **Networks → Tunnels**에서 Cloudflared 터널을 만들고, 설치 명령의 `--token` 뒤 값을 복사합니다.
2. 터널의 **Public Hostname**에 도메인(또는 `*.example.com` 와일드카드)을 추가하고 Service는 **HTTP**, URL은 **`coolify-proxy:80`** 으로 지정합니다. 와일드카드라면 DNS에 `*` → `<터널 ID>.cfargotunnel.com` 프록시 CNAME을 추가합니다.
3. **서버 → 컨트롤러 → 프록시 → Cloudflare Tunnel (외부 트래픽)** 에서 토큰을 붙여 넣고 **적용 후 프록시 재시작**을 누릅니다.
4. 앱을 다시 배포하고 서비스를 다시 시작합니다. 터널 뒤에서는 HTTPS를 Cloudflare가 처리하므로 프록시는 HTTP로만 서비스하고(리다이렉트 루프 방지), 앱에는 원래 스킴(https)과 클라이언트 IP가 `X-Forwarded-*` 헤더로 전달됩니다.

컨트롤러를 통해 라우팅되는 워커의 앱도 같은 터널로 공개됩니다. 자세한 동작과 문제 해결은 [docs/ko/07-내부망-서버와-터널.md](docs/ko/07-내부망-서버와-터널.md)를 보세요.

### SADP-Docker 새 버전으로 올리기

```bash
cd /opt/SADP-Docker && git pull
sudo bash scripts/SADP-Docker-deploy.sh
```

올리기 전에 **설정 → Backup**에서 인스턴스 백업을 먼저 받아 두세요.

### 다른 브랜치로 바꾸기 (데이터 유지)

```bash
cd /opt/SADP-Docker
git fetch origin
git checkout <브랜치>
git pull
sudo bash scripts/SADP-Docker-deploy.sh
```

새 브랜치의 코드로 이미지를 다시 빌드하고, 새 마이그레이션만 추가로 실행됩니다. 데이터베이스는 **되돌려지지 않으므로**, 마이그레이션이 더 적은 예전 브랜치로 돌아가면 오류가 날 수 있습니다. 그럴 때는 아래처럼 아예 새로 설치하세요.

### 브랜치를 바꾸면서 아예 새로 설치하기 (데이터 삭제)

**SADP-Docker의 계정, 프로젝트, 설정, 이 서버에 배포한 앱 데이터가 모두 지워지고 되돌릴 수 없습니다.** 필요한 것은 먼저 백업하고, `/data/coolify/source/.env`도 서버 밖에 복사해 두세요.

1. 기존 SADP-Docker를 지웁니다.

   ```bash
   # 프록시와 센티널 컨테이너 삭제 (없으면 그냥 넘어갑니다)
   sudo docker rm -f coolify-proxy coolify-sentinel 2>/dev/null || true

   # SADP-Docker 컨테이너, DB·Redis 볼륨, coolify 네트워크 삭제
   cd /data/coolify/source
   sudo docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml down -v --remove-orphans

   # 데이터 폴더(.env, SSH 키, 앱·DB·백업 파일) 삭제
   cd /
   sudo rm -rf /data/coolify
   ```

   이 서버에 배포한 앱 컨테이너가 남아 있으면 `coolify` 네트워크가 지워지지 않습니다. `docker ps -a`로 확인해 지운 뒤 `sudo docker network rm coolify`를 실행하세요.

2. 원하는 브랜치로 바꾼 뒤 처음 설치할 때와 같이 1단계, 2단계를 다시 실행합니다.

   ```bash
   cd /opt/SADP-Docker
   git fetch origin
   git checkout <브랜치>
   git pull
   sudo bash scripts/install.sh
   sudo bash scripts/SADP-Docker-deploy.sh
   ```

   `.env`(DB 비밀번호, APP_KEY)와 SSH 키가 새로 만들어지므로, 예전 백업을 복원하려면 1단계에서 복사해 둔 `.env`의 `APP_KEY`가 필요합니다.

> 새로 설치하면 설치 스크립트가 `~/.ssh/authorized_keys`에 `coolify` 주석이 붙은 키를 추가합니다. 예전 키 줄이 남아 있어도 동작에는 문제없지만, 정리하려면 예전 `coolify` 줄을 지우세요.

<!-- ## Quick start

Install Coolify on a supported server with one command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

Read the [installation guide](https://coolify.io/docs/installation) for requirements and detailed instructions. You can also review the [installation script](./scripts/install.sh) before you run it. -->
