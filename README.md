<div align="center">

# PADED

**Coolify기반 Kreonet연구망 사용자들이 쉽게 앱을 배포하고 여러가지 위험을 방지위해 추가로 기능을 넣은 플랫폼**

</div>

## 왜 PADED 따로 나누었나요?

## 그리고 Coolify는 뭐에요?
Coolify는 Heroku, Netlify, Vercel을 대체할 수 있는 오픈 소스이자 자체 호스팅(self-hostable)이 가능한 솔루션입니다. 이를 통해 사용자는 자신의 하드웨어에서 서버, 애플리케이션, 데이터베이스를 직접 관리할 수 있으며, SSH 연결만 있으면 바로 이용 가능합니다.

VPS, 베어메탈 서버, 라즈베리 파이(Raspberry Pi) 등 SSH 연결을 지원하는 어떤 서버에서든 사용할 수 있습니다. Coolify는 인프라에 대한 통제권을 유지하면서도 클라우드 플랫폼이 제공하는 편리함을 누릴 수 있게 해줍니다.

모든 설정 정보는 사용자의 서버에 저장됩니다. 따라서 Coolify 사용을 중단하더라도 실행 중인 리소스는 계속 작동하며, 여전히 관리 가능한 상태로 유지됩니다.

## PADED는 어떤 기능을 제공해주나요?
저희는 정부기관에서 요구사항이나 보안을 위해서 아래와 같은 기능이 추가됨니다.
- **앱별 로그 기록:** 앱에서 통신되고있는 트래픽 정보나 앱에서 일어나는 로그를 수집을 할수 있습니다.
- **통합모니터링:** 중앙에 있는 관리자가 한눈에 관리를 할수 있음니다.
- **독립적인공간:** 원래는 독립적인 공간이 있을려면 서버를 분리해서 써야 하지만 여기는 공통된 서버에서 그룹별 및 사용자별로 분리가 됨니다.
- **패키지 취약점 검사:** tinvy라는 보안 스캐너를 이용해서 배포를 할때 패키지별로 검사를 합니다. 이상한게 발견되면 사용자나 레포에 직접적으로 알려줌니다.
- **OIDC지원:** 정부나 연구관에서 별도로 SSO을 사용한 경우 OIDC을 이용해 바로 로그인이 가능합니다.

Coolify와 같이 아래와같은 기능은 공통적으로 제공됨니다.
----------------------------------------------------
- **모든 애플리케이션 배포:** Nixpacks, Railpack, Dockerfile, Docker Compose 또는 사전 빌드된 Docker 이미지를 사용하여 GitHub, GitLab, Bitbucket, Gitea 등에서 빌드 및 배포하세요.
- **데이터베이스 및 서비스 실행:** 관리형 데이터베이스와 300개 이상의 원클릭 서비스를 영구 스토리지 및 자동 생성된 자격 증명과 함께 배포하세요.
- **배포 자동화:** Git 푸시 시 배포, 풀 리퀘스트(PR) 미리보기 생성, 배포 웹훅 호출, 그리고 이전 애플리케이션 이미지로의 롤백 기능을 활용하세요.
- **네트워킹 관리:** 사용자 지정 도메인, 자동 HTTPS 인증서, 리버스 프록시, 상태 확인(health check) 및 컨테이너 네트워크를 설정하세요.
- **인프라 운영:** 다수의 서버 관리, 배포 및 런타임 로그 확인, 컨테이너 터미널 접속, 리소스 상태 모니터링을 수행하세요.
- **워크로드 보호:** 데이터베이스 및 스토리지 백업, 예약 작업, 환경 변수, 보안 정보(secrets), 알림 기능을 설정하세요.
- **워크플로 통합:** 대시보드, API, CLI, MCP 및 팀 단위의 접근 제어 기능을 통해 리소스를 관리하세요.

<!-- ## Quick start

Install Coolify on a supported server with one command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

Read the [installation guide](https://coolify.io/docs/installation) for requirements and detailed instructions. You can also review the [installation script](./scripts/install.sh) before you run it. -->
