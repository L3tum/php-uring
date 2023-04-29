extern int errno;

uint32_t htonl(uint32_t hostlong);
uint16_t htons(uint16_t hostshort);
uint32_t ntohl(uint32_t netlong);
uint16_t ntohs(uint16_t netshort);

typedef uint16_t sa_family_t;
typedef uint16_t in_port_t;
typedef uint32_t socklen_t;

typedef uint32_t in_addr_t;

typedef struct _in_addr {
    in_addr_t s_addr;
} in_addr;

struct sockaddr {
    sa_family_t  sa_family;  /* Address family. */
    char         sa_data[];  /* Socket address (variable-length data). */
};

typedef struct _sockaddr_in {
   sa_family_t    sin_family; /* address family: AF_INET */
   in_port_t      sin_port;   /* port in network byte order */
   in_addr        sin_addr;   /* internet address */
} sockaddr_in;

int inet_aton(const char *cp, in_addr *inp);
char *inet_ntoa(in_addr in);

int socket(int domain, int type, int protocol);
int setsockopt(int sockfd, int level, int optname, const void *optval, socklen_t optlen);
int bind(int socket, const sockaddr_in *addr, socklen_t addrlen);
int listen(int socket, int backlog);
int close(int fd);

int get_nprocs();
